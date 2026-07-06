<?php

namespace App\Http\Controllers\Api;

use App\Actions\OpenClaw\ResolverPartnerLogistico;
use App\Http\Controllers\Controller;
use App\Models\Cotizacion;
use App\Models\MovimientoFinanciero;
use App\Models\OrdenServicio;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Operational reports for OpenClaw (/resumen_dia, /resumen_semana, cortes).
 * Read only. Finances come straight from movimientos_financieros (real);
 * anything projected from undelivered orders is explicitly labeled estimado.
 */
class InternalReportController extends Controller
{
    public function __construct(private ResolverPartnerLogistico $resolverPartnerLogistico) {}

    public function daily(Request $request): JsonResponse
    {
        $filtros = $this->validarFiltros($request, [
            'date' => ['nullable', 'date'],
        ]);

        $fecha = CarbonImmutable::parse($filtros['date'] ?? today()->toDateString());

        return response()->json($this->reporte($fecha, $fecha, $filtros) + [
            'date' => $fecha->toDateString(),
        ]);
    }

    public function weekly(Request $request): JsonResponse
    {
        $filtros = $this->validarFiltros($request, [
            'week_start' => ['nullable', 'date'],
        ]);

        $inicio = CarbonImmutable::parse($filtros['week_start'] ?? today()->startOfWeek()->toDateString());
        $fin = $inicio->addDays(6);

        return response()->json($this->reporte($inicio, $fin, $filtros) + [
            'week_start' => $inicio->toDateString(),
            'week_end' => $fin->toDateString(),
        ]);
    }

    /**
     * Cash cut (daily or weekly): real income/expense from financial movements,
     * delivered orders in the period, and the estimated amount still to collect.
     */
    public function cashCut(Request $request): JsonResponse
    {
        $filtros = $this->validarFiltros($request, [
            'period' => ['nullable', Rule::in(['daily', 'weekly'])],
            'date' => ['nullable', 'date'],
            'week_start' => ['nullable', 'date'],
        ]);

        $periodo = $filtros['period'] ?? 'daily';
        if ($periodo === 'weekly') {
            $desde = CarbonImmutable::parse($filtros['week_start'] ?? today()->startOfWeek()->toDateString());
            $hasta = $desde->addDays(6);
        } else {
            $desde = CarbonImmutable::parse($filtros['date'] ?? today()->toDateString());
            $hasta = $desde;
        }

        $warnings = [];
        $partnerId = $this->resolverPartner($filtros, $warnings);

        $movimientos = MovimientoFinanciero::query()
            ->whereDate('fecha', '>=', $desde->toDateString())
            ->whereDate('fecha', '<=', $hasta->toDateString())
            ->when($partnerId, fn (Builder $query, int $id) => $query->where('partner_id', $id))
            ->orderBy('fecha')
            ->get();

        $ingresos = (float) $movimientos->where('tipo', 'ingreso')->sum('monto');
        $egresos = (float) $movimientos->where('tipo', 'egreso')->sum('monto');

        $entregadas = $this->ordenesBase($partnerId)
            ->where('estado', 'entregado')
            ->whereDate('fecha_entrega', '>=', $desde->toDateString())
            ->whereDate('fecha_entrega', '<=', $hasta->toDateString())
            ->with(['cliente', 'detalles', 'refacciones'])
            ->get();

        $pendientePorCobrar = (float) $this->ordenesBase($partnerId)
            ->where('estado', 'listo_para_entregar')
            ->sum('total_cliente');

        $respuesta = [
            'period' => $periodo,
            'from' => $desde->toDateString(),
            'to' => $hasta->toDateString(),
            // Basado en movimientos financieros registrados: cifras reales.
            'source' => 'movimientos_financieros (real)',
            'ingresos' => $ingresos,
            'egresos' => $egresos,
            'utilidad' => round($ingresos - $egresos, 2),
            'ordenes_entregadas' => $entregadas->count(),
            'servicios' => (float) $entregadas->sum(fn (OrdenServicio $orden): float => (float) $orden->detalles->sum('subtotal')),
            'refacciones' => (float) $entregadas->sum(fn (OrdenServicio $orden): float => (float) $orden->refacciones->sum('precio_total_cliente')),
            'pendiente_por_cobrar_estimado' => $pendientePorCobrar,
            'pendiente_por_cobrar_nota' => 'Estimado: suma de total_cliente de órdenes listas para entregar (aún sin finanzas).',
            'warnings' => $warnings,
        ];

        if ($this->incluirDetalles($filtros)) {
            $respuesta['detalles'] = $movimientos->map(fn (MovimientoFinanciero $movimiento): array => [
                'fecha' => $movimiento->fecha?->toDateString(),
                'tipo' => $movimiento->tipo,
                'categoria' => $movimiento->categoria,
                'monto' => (float) $movimiento->monto,
                'descripcion' => $movimiento->descripcion,
                'orden_servicio_id' => $movimiento->orden_servicio_id,
            ])->values()->all();
        }

        return response()->json($respuesta);
    }

    /**
     * Shared aggregation for daily/weekly summaries over [desde, hasta].
     *
     * @param  array<string, mixed>  $filtros
     * @return array<string, mixed>
     */
    private function reporte(CarbonImmutable $desde, CarbonImmutable $hasta, array $filtros): array
    {
        $warnings = [];
        $partnerId = $this->resolverPartner($filtros, $warnings);

        $creadas = $this->ordenesBase($partnerId)
            ->whereDate('fecha_recepcion', '>=', $desde->toDateString())
            ->whereDate('fecha_recepcion', '<=', $hasta->toDateString());
        $entregadas = $this->ordenesBase($partnerId)
            ->where('estado', 'entregado')
            ->whereDate('fecha_entrega', '>=', $desde->toDateString())
            ->whereDate('fecha_entrega', '<=', $hasta->toDateString());

        // Snapshot actual (no del periodo): lo que está pendiente HOY es lo accionable.
        $porEstado = $this->ordenesBase($partnerId)
            ->whereNotIn('estado', ['entregado', 'cancelado'])
            ->selectRaw('estado, count(*) as total')
            ->groupBy('estado')
            ->pluck('total', 'estado');
        $sinTecnico = $this->ordenesBase($partnerId)
            ->whereNotIn('estado', ['entregado', 'cancelado'])
            ->whereNull('partner_tecnico_id')
            ->count();

        $cotizacionesCreadas = Cotizacion::query()
            ->whereDate('fecha', '>=', $desde->toDateString())
            ->whereDate('fecha', '<=', $hasta->toDateString())
            ->count();
        $cotizacionesPendientes = Cotizacion::query()->whereIn('estado', ['borrador', 'enviada'])->get();
        // Sin timestamp de cambio de estado en cotizaciones: se aproxima con updated_at.
        $cotizacionesAprobadas = Cotizacion::query()
            ->where('estado', 'aceptada')
            ->whereDate('updated_at', '>=', $desde->toDateString())
            ->whereDate('updated_at', '<=', $hasta->toDateString())
            ->count();

        $movimientos = MovimientoFinanciero::query()
            ->whereDate('fecha', '>=', $desde->toDateString())
            ->whereDate('fecha', '<=', $hasta->toDateString())
            ->when($partnerId, fn (Builder $query, int $id) => $query->where('partner_id', $id))
            ->selectRaw('tipo, sum(monto) as total')
            ->groupBy('tipo')
            ->pluck('total', 'tipo');
        $ingresos = (float) ($movimientos->get('ingreso') ?? 0);
        $egresos = (float) ($movimientos->get('egreso') ?? 0);

        $pendientePorCobrar = (float) $this->ordenesBase($partnerId)
            ->where('estado', 'listo_para_entregar')
            ->sum('total_cliente');

        $listas = (int) ($porEstado->get('listo_para_entregar') ?? 0);
        $alertas = [];
        if ($listas > 0) {
            $alertas[] = "{$listas} ".($listas === 1 ? 'orden lista' : 'órdenes listas').' para entregar';
        }
        if ($sinTecnico > 0) {
            $alertas[] = "{$sinTecnico} ".($sinTecnico === 1 ? 'orden' : 'órdenes').' sin técnico asignado';
        }
        if ($cotizacionesPendientes->count() > 0) {
            $alertas[] = "{$cotizacionesPendientes->count()} ".($cotizacionesPendientes->count() === 1 ? 'cotización pendiente' : 'cotizaciones pendientes').' de respuesta';
        }

        $respuesta = [
            'ordenes' => [
                'creadas' => $creadas->count(),
                'entregadas' => $entregadas->count(),
                'listas_para_entregar' => $listas,
                'en_proceso' => (int) ($porEstado->get('en_proceso') ?? 0),
                'sin_tecnico' => $sinTecnico,
                'por_estado' => $porEstado->all(),
            ],
            'cotizaciones' => [
                'creadas' => $cotizacionesCreadas,
                'pendientes' => $cotizacionesPendientes->count(),
                'aprobadas' => $cotizacionesAprobadas,
                'aprobadas_nota' => 'Aproximado por fecha de última edición (el modelo no guarda cuándo cambió el estado).',
                'total_pendiente' => (float) $cotizacionesPendientes->sum('total'),
            ],
            'finanzas' => [
                'source' => 'movimientos_financieros (real)',
                'ingresos' => $ingresos,
                'egresos' => $egresos,
                'utilidad' => round($ingresos - $egresos, 2),
                'pendiente_por_cobrar_estimado' => $pendientePorCobrar,
            ],
            'alertas' => $alertas,
            'warnings' => $warnings,
        ];

        if ($this->incluirDetalles($filtros)) {
            $respuesta['detalles'] = [
                'ordenes_creadas' => $creadas->with(['cliente', 'equipo'])->orderBy('fecha_recepcion')->get()
                    ->map(fn (OrdenServicio $orden): array => $this->resumenOrden($orden))->values()->all(),
                'ordenes_listas' => $this->ordenesBase($partnerId)->where('estado', 'listo_para_entregar')
                    ->with(['cliente', 'equipo'])->get()
                    ->map(fn (OrdenServicio $orden): array => $this->resumenOrden($orden))->values()->all(),
                'cotizaciones_pendientes' => $cotizacionesPendientes->load('cliente')
                    ->map(fn (Cotizacion $cotizacion): array => [
                        'id' => $cotizacion->id,
                        'folio' => $cotizacion->folio,
                        'cliente' => $cotizacion->cliente?->nombre,
                        'total' => (float) $cotizacion->total,
                        'estado' => $cotizacion->estado,
                        'show_url' => route('admin.cotizaciones.show', $cotizacion),
                    ])->values()->all(),
            ];
        }

        return $respuesta;
    }

    /**
     * @param  array<string, list<mixed>>  $reglasExtra
     * @return array<string, mixed>
     */
    private function validarFiltros(Request $request, array $reglasExtra): array
    {
        return $request->validate($reglasExtra + [
            'partner' => ['nullable', 'string', 'max:255'],
            'include_details' => ['nullable', 'in:true,false,1,0'],
        ]);
    }

    private function ordenesBase(?int $partnerId): Builder
    {
        return OrdenServicio::query()
            ->when($partnerId, fn (Builder $query, int $id) => $query->where('partner_recepcion_id', $id));
    }

    /**
     * @param  array<string, mixed>  $filtros
     * @param  array<int, string>  $warnings
     */
    private function resolverPartner(array $filtros, array &$warnings): ?int
    {
        if (blank($filtros['partner'] ?? null)) {
            return null;
        }

        $resultado = $this->resolverPartnerLogistico->ejecutar((string) $filtros['partner']);

        if ($resultado['id'] === null) {
            $warnings[] = "No se pudo filtrar por partner \"{$filtros['partner']}\": ".($resultado['warning'] ?? 'sin coincidencia.');
        }

        return $resultado['id'];
    }

    /**
     * @param  array<string, mixed>  $filtros
     */
    private function incluirDetalles(array $filtros): bool
    {
        return in_array($filtros['include_details'] ?? null, ['true', '1', 1, true], true);
    }

    /**
     * @return array<string, mixed>
     */
    private function resumenOrden(OrdenServicio $orden): array
    {
        return [
            'id' => $orden->id,
            'folio' => $orden->folio,
            'estado' => $orden->estado,
            'cliente' => $orden->cliente?->nombre,
            'equipo' => $orden->equipo
                ? trim("{$orden->equipo->tipo_equipo} {$orden->equipo->marca} ".(string) $orden->equipo->modelo)
                : null,
            'total_cliente' => (float) $orden->total_cliente,
            'show_url' => route('admin.ordenes-servicio.show', $orden),
        ];
    }
}
