<?php

namespace App\Http\Controllers\Api;

use App\Actions\OpenClaw\ResolverPartnerLogistico;
use App\Http\Controllers\Controller;
use App\Models\Cotizacion;
use App\Models\OrdenServicio;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Pending / overdue detection for OpenClaw follow-ups. Read only, no timers:
 * OpenClaw polls this whenever it wants (e.g. daily). Time in the current
 * status comes from the latest historial_estados entry; orders with no history
 * fall back to fecha_recepcion.
 */
class InternalFollowUpController extends Controller
{
    /** Days without movement before each situation is flagged. */
    private const DIAS_RECIBIDO_SIN_AVANCE = 2;

    private const DIAS_EN_DIAGNOSTICO = 3;

    private const DIAS_LISTA_SIN_ENTREGAR = 1;

    private const DIAS_COTIZACION_SIN_RESPUESTA = 2;

    public function __construct(private ResolverPartnerLogistico $resolverPartner) {}

    public function __invoke(Request $request): JsonResponse
    {
        $filtros = $request->validate([
            'type' => ['nullable', Rule::in(['orders', 'quotes', 'all'])],
            'overdue_days' => ['nullable', 'integer', 'min:0'],
            'estado' => ['nullable', 'string', 'max:50'],
            'partner' => ['nullable', 'string', 'max:255'],
            'date' => ['nullable', 'date'],
        ]);

        $tipo = $filtros['type'] ?? 'all';
        $referencia = CarbonImmutable::parse($filtros['date'] ?? today()->toDateString());
        $warnings = [];
        $partnerId = null;

        if (filled($filtros['partner'] ?? null)) {
            $resultado = $this->resolverPartner->ejecutar((string) $filtros['partner']);
            $partnerId = $resultado['id'];
            if ($partnerId === null) {
                $warnings[] = "No se pudo filtrar por partner \"{$filtros['partner']}\": sin coincidencia única.";
            }
        }

        $items = [];

        if (in_array($tipo, ['orders', 'all'], true)) {
            $items = [...$items, ...$this->pendientesDeOrdenes($filtros, $partnerId, $referencia)];
        }
        if (in_array($tipo, ['quotes', 'all'], true)) {
            $items = [...$items, ...$this->pendientesDeCotizaciones($filtros, $referencia)];
        }

        return response()->json([
            'date' => $referencia->toDateString(),
            'items' => array_values($items),
            'warnings' => $warnings,
        ]);
    }

    /**
     * @param  array<string, mixed>  $filtros
     * @return array<int, array<string, mixed>>
     */
    private function pendientesDeOrdenes(array $filtros, ?int $partnerId, CarbonImmutable $referencia): array
    {
        $overrideDias = isset($filtros['overdue_days']) ? (int) $filtros['overdue_days'] : null;

        $ordenes = OrdenServicio::query()
            ->whereNotIn('estado', ['entregado', 'cancelado'])
            ->when($partnerId, fn (Builder $query, int $id) => $query->where('partner_recepcion_id', $id))
            ->when($filtros['estado'] ?? null, fn (Builder $query, string $estado) => $query->where('estado', $estado))
            ->with(['cliente', 'partnerRecepcion', 'refacciones', 'historialEstados' => fn ($query) => $query->latest('id')])
            ->get();

        $items = [];

        foreach ($ordenes as $orden) {
            $ultimoCambio = $orden->historialEstados->first()?->created_at ?? $orden->fecha_recepcion;
            $diasEnEstado = $ultimoCambio ? (int) $ultimoCambio->copy()->startOfDay()->diffInDays($referencia->startOfDay(), false) : 0;
            $diasEnEstado = max(0, $diasEnEstado);

            if ($orden->estado === 'recibido' && $diasEnEstado >= ($overrideDias ?? self::DIAS_RECIBIDO_SIN_AVANCE)) {
                $items[] = $this->itemOrden($orden, "Recibida hace {$diasEnEstado} días sin avance", 'Asignar técnico o iniciar diagnóstico');
            }

            if ($orden->estado === 'en_diagnostico' && $diasEnEstado >= ($overrideDias ?? self::DIAS_EN_DIAGNOSTICO)) {
                $items[] = $this->itemOrden($orden, "En diagnóstico desde hace {$diasEnEstado} días", 'Revisar avance del diagnóstico');
            }

            if ($orden->estado === 'listo_para_entregar' && $diasEnEstado >= ($overrideDias ?? self::DIAS_LISTA_SIN_ENTREGAR)) {
                $items[] = $this->itemOrden($orden, "Lista para entregar desde hace {$diasEnEstado} ".($diasEnEstado === 1 ? 'día' : 'días'), 'Enviar mensaje al cliente para agendar la entrega');
            }

            if ($orden->partner_tecnico_id === null && in_array($orden->estado, ['recibido', 'en_diagnostico', 'cotizacion_aprobada', 'en_proceso'], true)) {
                $items[] = $this->itemOrden($orden, 'Sin técnico asignado', 'Asignar partner técnico en el panel');
            }

            $sinPrecio = $orden->refacciones->filter(
                fn ($refaccion): bool => (float) $refaccion->costo_unitario <= 0 || (float) $refaccion->precio_unitario_cliente <= 0,
            );
            if ($sinPrecio->isNotEmpty()) {
                $detalle = $sinPrecio->pluck('descripcion')->implode(', ');
                $items[] = $this->itemOrden($orden, "Refacciones sin costo o precio: {$detalle}", 'Capturar costo y precio de las refacciones');
            }
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $filtros
     * @return array<int, array<string, mixed>>
     */
    private function pendientesDeCotizaciones(array $filtros, CarbonImmutable $referencia): array
    {
        $dias = isset($filtros['overdue_days']) ? (int) $filtros['overdue_days'] : self::DIAS_COTIZACION_SIN_RESPUESTA;

        return Cotizacion::query()
            ->whereIn('estado', ['borrador', 'enviada'])
            ->with('cliente')
            ->get()
            ->filter(function (Cotizacion $cotizacion) use ($referencia, $dias): bool {
                $fecha = $cotizacion->fecha ?? $cotizacion->created_at;

                return $fecha !== null && (int) $fecha->copy()->startOfDay()->diffInDays($referencia->startOfDay(), false) >= $dias;
            })
            ->map(function (Cotizacion $cotizacion) use ($referencia): array {
                $fecha = $cotizacion->fecha ?? $cotizacion->created_at;
                $dias = max(0, (int) $fecha->copy()->startOfDay()->diffInDays($referencia->startOfDay(), false));

                return [
                    'type' => 'quote',
                    'id' => $cotizacion->id,
                    'folio' => $cotizacion->folio,
                    'cliente' => $cotizacion->cliente?->nombre,
                    'estado' => $cotizacion->estado,
                    'total' => (float) $cotizacion->total,
                    'reason' => "Cotización sin respuesta desde hace {$dias} ".($dias === 1 ? 'día' : 'días'),
                    'suggested_action' => 'Enviar seguimiento al cliente',
                    'show_url' => route('admin.cotizaciones.show', $cotizacion),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function itemOrden(OrdenServicio $orden, string $razon, string $accion): array
    {
        return [
            'type' => 'order',
            'id' => $orden->id,
            'folio' => $orden->folio,
            'cliente' => $orden->cliente?->nombre,
            'estado' => $orden->estado,
            'estado_label' => $orden->estadoLabel(),
            'sucursal' => $orden->partnerRecepcion?->nombre,
            'reason' => $razon,
            'suggested_action' => $accion,
            'show_url' => route('admin.ordenes-servicio.show', $orden),
        ];
    }
}
