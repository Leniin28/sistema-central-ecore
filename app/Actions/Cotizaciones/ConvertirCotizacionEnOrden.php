<?php

namespace App\Actions\Cotizaciones;

use App\Actions\OpenClaw\ObtenerUsuarioSistema;
use App\Actions\OpenClaw\ResolverPartnerLogistico;
use App\Actions\Ordenes\CalcularTotalesOrdenServicio;
use App\Actions\Ordenes\CrearOrdenServicio;
use App\Models\Cotizacion;
use App\Models\Equipo;
use App\Models\OrdenServicio;
use App\Models\OrdenServicioDetalle;
use App\Models\OrdenServicioRefaccion;
use App\Models\Servicio;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Converts an accepted quote into an order. Quote conversion deliberately does
 * not use the OpenClaw /changes matcher: an accepted quote is the commercial
 * source of truth and its sold lines may be ad-hoc.
 */
class ConvertirCotizacionEnOrden
{
    public function __construct(
        private CrearOrdenServicio $crearOrden,
        private CalcularTotalesOrdenServicio $calculadora,
        private ObtenerUsuarioSistema $usuarioSistema,
        private ResolverPartnerLogistico $resolverPartner,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array{orden: OrdenServicio, cotizacion: Cotizacion, created: bool, warnings: array<int, string>}
     */
    public function ejecutar(Cotizacion $cotizacion, array $data): array
    {
        return DB::transaction(function () use ($cotizacion, $data): array {
            $cotizacion = Cotizacion::query()->lockForUpdate()->findOrFail($cotizacion->id);
            $cotizacion->loadMissing(['cliente', 'equipo', 'items']);

            $ordenVinculada = $cotizacion->ordenServicio()->first();
            if ($ordenVinculada) {
                $this->reconciliarLineas($ordenVinculada, $this->mapearItems($cotizacion));
                $this->recalcularTotalesOrden($ordenVinculada);

                return [
                    'orden' => $ordenVinculada->fresh(['cliente', 'equipo', 'partnerRecepcion']),
                    'cotizacion' => $cotizacion,
                    'created' => false,
                    'warnings' => [],
                ];
            }

            if (! empty($data['external_id'])) {
                $existente = OrdenServicio::query()->where('external_id', $data['external_id'])->first();

                if ($existente) {
                    return [
                        'orden' => $existente->loadMissing(['cliente', 'equipo', 'partnerRecepcion']),
                        'cotizacion' => $cotizacion,
                        'created' => false,
                        'warnings' => [],
                    ];
                }
            }

            $warnings = [];
            $equipo = $this->resolverEquipo($cotizacion, $data, $warnings);
            $partner = $this->resolverPartnerLogistico($data, $warnings);
            $recepcion = $data['recepcion'] ?? [];
            $falla = trim((string) ($recepcion['falla_reportada'] ?? ''));
            $origen = (string) ($data['origen'] ?? 'openclaw-cotizacion');
            $origenNota = (string) ($data['origen_nota'] ?? 'OpenClaw');
            $bloques = [
                'Falla reportada:'."\n".($falla !== '' ? $falla : 'Servicio autorizado desde cotización '.$cotizacion->folio),
                "Origen:\nConvertida desde la cotización {$cotizacion->folio} por OpenClaw.",
            ];
            $bloques[1] = "Origen:\nConvertida desde la cotización {$cotizacion->folio} por {$origenNota}.";

            if (filled($recepcion['notas'] ?? null)) {
                $bloques[] = "Notas:\n".trim((string) $recepcion['notas']);
            }

            $lineas = $this->mapearItems($cotizacion);
            $this->validarTrazabilidadLineas($lineas);

            $orden = $this->crearOrden->ejecutar(
                [
                    'cotizacion_id' => $cotizacion->id,
                    'cliente_id' => $cotizacion->cliente_id,
                    'equipo_id' => $equipo?->id,
                    'partner_recepcion_id' => $partner,
                    'tipo_recepcion' => 'directo',
                    'notas' => implode("\n\n", $bloques),
                    'costo_tecnico' => null,
                    'external_id' => $data['external_id'] ?? null,
                    'origen' => $origen,
                ],
                [],
                [],
                $data['actor'] ?? $this->usuarioSistema->ejecutar(),
            );

            $this->reconciliarLineas($orden, $lineas);
            $this->recalcularTotalesOrden($orden);

            if ($cotizacion->estado !== 'aceptada') {
                if ($cotizacion->esEditable()) {
                    $cotizacion->update(['estado' => 'aceptada']);
                } else {
                    $warnings[] = "La cotización {$cotizacion->folio} está en estado \"{$cotizacion->estado}\" y no se cambió a aceptada.";
                }
            }

            $cotizacion->update([
                'notas' => trim(((string) $cotizacion->notas ? $cotizacion->notas."\n\n" : '')
                    ."Convertida en la orden {$orden->folio} por OpenClaw."),
            ]);

            if ($origen !== 'openclaw-cotizacion') {
                $cotizacion->update([
                    'notas' => str_replace(' por OpenClaw.', " por {$origenNota}.", (string) $cotizacion->notas),
                ]);
            }

            return [
                'orden' => $orden->fresh(['cliente', 'equipo', 'partnerRecepcion']),
                'cotizacion' => $cotizacion->fresh(),
                'created' => true,
                'warnings' => $warnings,
            ];
        });
    }

    /** @return array{detalles: array<int, array<string, mixed>>, refacciones: array<int, array<string, mixed>>} */
    private function mapearItems(Cotizacion $cotizacion): array
    {
        $detalles = [];
        $refacciones = [];

        foreach ($cotizacion->items as $item) {
            if ($item->tipo === 'servicio') {
                $detalles[] = [
                    'cotizacion_item_id' => $item->id,
                    'servicio_id' => $this->resolverServicioExacto($item),
                    'descripcion' => $item->descripcion,
                    'cantidad' => (int) $item->cantidad,
                    'precio_unitario' => (float) $item->precio_unitario,
                    'costo_unitario' => $item->costo_unitario,
                ];

                continue;
            }

            $refacciones[] = [
                'cotizacion_item_id' => $item->id,
                'descripcion' => $item->descripcion,
                'cantidad' => (int) $item->cantidad,
                'precio_unitario_cliente' => (float) $item->precio_unitario,
                'costo_unitario' => $item->costo_unitario,
                'notas' => 'Tomado de la cotización '.$cotizacion->folio.' (tipo '.$item->tipo.').',
            ];
        }

        return ['detalles' => $detalles, 'refacciones' => $refacciones];
    }

    /**
     * A DB unique index protects each destination table. The cross-table
     * invariant needs this explicit in-memory guard until both types share a
     * transfer ledger.
     *
     * @param  array{detalles: array<int, array<string, mixed>>, refacciones: array<int, array<string, mixed>>}  $lineas
     */
    private function validarTrazabilidadLineas(array $lineas): void
    {
        $detalles = collect($lineas['detalles'])->pluck('cotizacion_item_id');
        $refacciones = collect($lineas['refacciones'])->pluck('cotizacion_item_id');

        if ($detalles->intersect($refacciones)->isNotEmpty()) {
            throw ValidationException::withMessages([
                'cotizacion' => 'Una línea de cotización no puede transferirse como servicio y refacción a la vez.',
            ]);
        }

    }

    /**
     * Reconciles each source item independently. A correct existing transfer
     * is retained, missing lines are copied, and the opposite line type is
     * always rejected. This is idempotent for both panel replays and Case A.
     *
     * @param  array{detalles: array<int, array<string, mixed>>, refacciones: array<int, array<string, mixed>>}  $lineas
     */
    private function reconciliarLineas(OrdenServicio $orden, array $lineas): void
    {
        foreach ($lineas['detalles'] as $detalle) {
            $itemId = $detalle['cotizacion_item_id'];
            if (OrdenServicioRefaccion::query()->where('cotizacion_item_id', $itemId)->exists()) {
                throw ValidationException::withMessages([
                    'cotizacion' => 'Una línea de cotización ya está transferida como refacción.',
                ]);
            }

            $orden->detalles()->firstOrCreate(
                ['cotizacion_item_id' => $itemId],
                $this->calculadora->detalle($detalle),
            );
        }

        foreach ($lineas['refacciones'] as $refaccion) {
            $itemId = $refaccion['cotizacion_item_id'];
            if (OrdenServicioDetalle::query()->where('cotizacion_item_id', $itemId)->exists()) {
                throw ValidationException::withMessages([
                    'cotizacion' => 'Una línea de cotización ya está transferida como servicio.',
                ]);
            }

            $orden->refacciones()->firstOrCreate(
                ['cotizacion_item_id' => $itemId],
                $this->calculadora->refaccion($refaccion),
            );
        }
    }

    /** Recalculate stored totals from persisted lines without overwriting them. */
    private function recalcularTotalesOrden(OrdenServicio $orden): void
    {
        $orden->load(['detalles', 'refacciones']);
        $totalServicios = (float) $orden->detalles->sum('subtotal');
        $totalRefacciones = (float) $orden->refacciones->sum('precio_total_cliente');
        $costoServicios = (float) $orden->detalles->sum('costo_total');
        $costoRefacciones = (float) $orden->refacciones->sum('costo_total');
        $totalCliente = $totalServicios + $totalRefacciones;
        $costosIncompletos = $orden->detalles->contains(fn ($detalle): bool => $detalle->costo_total === null)
            || $orden->refacciones->contains(fn ($refaccion): bool => $refaccion->costo_total === null)
            || ($orden->partner_tecnico_id !== null && $orden->costo_tecnico === null);

        $orden->update([
            'total_cliente' => $totalCliente,
            'utilidad_estimada' => $totalCliente - $costoServicios - $costoRefacciones - (float) $orden->costo_tecnico - (float) $orden->comision_logistica,
            'costos_incompletos' => $costosIncompletos,
        ]);
    }

    private function resolverServicioExacto(object $item): ?int
    {
        $descripcion = $this->normalizarDescripcion((string) $item->descripcion);
        if ($descripcion === '') {
            return null;
        }

        $coincidencias = Servicio::query()
            ->where('activo', true)
            ->get(['id', 'nombre'])
            ->filter(fn (Servicio $servicio): bool => $this->normalizarDescripcion($servicio->nombre) === $descripcion)
            ->values();

        return $coincidencias->count() === 1 ? $coincidencias->first()->id : null;
    }

    private function normalizarDescripcion(string $descripcion): string
    {
        return (string) str($descripcion)->ascii()->lower()->squish();
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, string>  $warnings
     */
    private function resolverEquipo(Cotizacion $cotizacion, array $data, array &$warnings): ?Equipo
    {
        if ($cotizacion->equipo) {
            return $cotizacion->equipo;
        }

        $nuevo = $data['equipo'] ?? [];
        if (blank($nuevo['tipo_equipo'] ?? null) && blank($nuevo['modelo'] ?? null)) {
            $warnings[] = 'La cotización no tiene equipo y no se envió uno; la orden quedó sin equipo. Asígnalo en el panel.';

            return null;
        }

        return Equipo::create([
            'cliente_id' => $cotizacion->cliente_id,
            'tipo_equipo' => $nuevo['tipo_equipo'] ?? $nuevo['modelo'] ?? 'Equipo',
            'marca' => $nuevo['marca'] ?? 'Sin marca',
            'modelo' => $nuevo['modelo'] ?? null,
            'numero_serie' => $nuevo['numero_serie'] ?? null,
            'password_equipo' => $nuevo['password_equipo'] ?? null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, string>  $warnings
     */
    private function resolverPartnerLogistico(array $data, array &$warnings): ?int
    {
        if (! empty($data['partner_recepcion_id'])) {
            return (int) $data['partner_recepcion_id'];
        }

        if (blank($data['partner_logistico'] ?? null)) {
            return null;
        }

        $resultado = $this->resolverPartner->ejecutar((string) $data['partner_logistico']);
        if ($resultado['warning'] !== null) {
            $warnings[] = $resultado['warning'];
        }

        return $resultado['id'];
    }
}
