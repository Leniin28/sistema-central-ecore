<?php

namespace App\Actions\Cotizaciones;

use App\Models\Cotizacion;
use App\Models\CotizacionItem;
use App\Models\OrdenServicio;
use App\Models\OrdenServicioDetalle;
use App\Models\OrdenServicioRefaccion;
use App\Models\ReconciliacionCotizacionOrden;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class ReconciliarCotizacionesHistoricas
{
    public function __construct(
        private CalcularTotalesCotizacion $calcularCotizacion,
        private SincronizarLineasCotizacionConOrden $sincronizarLineas,
    ) {}

    /** @return Collection<int, array<string, mixed>> */
    public function diagnosticar(?string $caseKey = null): Collection
    {
        $casos = collect(config('historical_quote_reconciliation.cases', []));

        if ($caseKey !== null) {
            if (! $casos->has($caseKey)) {
                throw new InvalidArgumentException("El caso {$caseKey} no está definido en el manifiesto histórico.");
            }

            $casos = $casos->only($caseKey);
        }

        return $casos->map(
            fn (array $definicion, string $key): array => $this->construirPlan($key, $definicion),
        )->values();
    }

    /** @return Collection<int, array<string, mixed>> */
    public function protegidasPorFinanzas(): Collection
    {
        return Cotizacion::query()
            ->where('estado', 'aceptada')
            ->whereHas('ordenServicio', fn ($query) => $query
                ->where('finanzas_generadas', true)
                ->orWhereHas('movimientosFinancieros'))
            ->with(['ordenServicio.movimientosFinancieros'])
            ->orderBy('id')
            ->get()
            ->map(fn (Cotizacion $cotizacion): array => [
                'cotizacion' => $cotizacion->folio,
                'orden' => $cotizacion->ordenServicio?->folio,
                'finanzas_generadas' => (bool) $cotizacion->ordenServicio?->finanzas_generadas,
                'movimientos' => $cotizacion->ordenServicio?->movimientosFinancieros->count() ?? 0,
            ]);
    }

    /** @return array{aplicada: bool, id: int, plan: array<string, mixed>} */
    public function aplicar(string $caseKey, string $fingerprint, int $actorId): array
    {
        $this->asegurarEsquemaInstalado();

        $definicion = config("historical_quote_reconciliation.cases.{$caseKey}");
        if (! is_array($definicion)) {
            throw new InvalidArgumentException("El caso {$caseKey} no está definido en el manifiesto histórico.");
        }

        return DB::transaction(function () use ($caseKey, $fingerprint, $actorId, $definicion): array {
            $existente = ReconciliacionCotizacionOrden::query()
                ->where('case_key', $caseKey)
                ->lockForUpdate()
                ->first();

            if ($existente) {
                if (! hash_equals($existente->fingerprint, $fingerprint)) {
                    throw ValidationException::withMessages([
                        'fingerprint' => 'El caso ya fue aplicado con una huella distinta.',
                    ]);
                }

                return ['aplicada' => false, 'id' => $existente->id, 'plan' => ['status' => 'applied']];
            }

            $actor = User::query()->lockForUpdate()->findOrFail($actorId);
            if (! $actor->isAdmin()) {
                throw ValidationException::withMessages(['actor' => 'El actor de reconciliación debe ser administrador.']);
            }

            $plan = $this->construirPlan($caseKey, $definicion, lock: true, ignorarAuditoria: true);
            if ($plan['status'] !== 'ready') {
                throw ValidationException::withMessages([
                    'case' => "El caso {$caseKey} no es aplicable: ".implode(' ', $plan['reasons']),
                ]);
            }
            if (! hash_equals($plan['fingerprint'], $fingerprint)) {
                throw ValidationException::withMessages([
                    'fingerprint' => 'La base cambió desde el dry-run. Genere una huella nueva y revise nuevamente el caso.',
                ]);
            }

            /** @var Cotizacion $cotizacionCanonica */
            $cotizacionCanonica = $plan['models']['canonical_quote'];
            /** @var OrdenServicio $ordenCanonica */
            $ordenCanonica = $plan['models']['canonical_order'];
            /** @var EloquentCollection<int, Cotizacion> $cotizacionesOrigen */
            $cotizacionesOrigen = $plan['models']['source_quotes'];
            /** @var EloquentCollection<int, OrdenServicio> $ordenesOrigen */
            $ordenesOrigen = $plan['models']['source_orders'];
            /** @var EloquentCollection<int, OrdenServicio> $ordenesParticipantes */
            $ordenesParticipantes = $plan['models']['all_orders'];
            $snapshotAntes = $plan['snapshot'];

            $this->aplicarDeduplicacionesRefacciones(
                $plan['manual_refaction_deduplications'],
                $cotizacionCanonica,
                $ordenCanonica,
            );

            foreach ($ordenesParticipantes as $orden) {
                if ($this->esAnteriorAlCutoff($orden)) {
                    $orden->detalles()->whereNull('cotizacion_item_id')->delete();
                }
            }

            foreach ($ordenesOrigen as $ordenOrigen) {
                $ordenOrigen->detalles()->whereNotNull('cotizacion_item_id')->update(['cotizacion_item_id' => null]);
                $ordenOrigen->refacciones()->whereNotNull('cotizacion_item_id')->update(['cotizacion_item_id' => null]);
                $ordenOrigen->update(['cotizacion_id' => null]);
            }

            foreach ($cotizacionesOrigen as $cotizacionOrigen) {
                $cotizacionOrigen->items()->update([
                    'cotizacion_origen_id' => $cotizacionOrigen->id,
                    'cotizacion_id' => $cotizacionCanonica->id,
                ]);
                $cotizacionOrigen->update(['cotizacion_canonica_id' => $cotizacionCanonica->id]);
            }

            $this->recalcularCotizacion($cotizacionCanonica->fresh('items'));
            $ordenCanonica->update([
                'cotizacion_id' => $cotizacionCanonica->id,
                'orden_canonica_id' => null,
            ]);
            $this->sincronizarLineas->sincronizar($ordenCanonica->fresh(), $cotizacionCanonica->fresh());

            foreach ($ordenesOrigen as $ordenOrigen) {
                $estadoAnterior = $ordenOrigen->estado;
                $ordenOrigen->update([
                    'orden_canonica_id' => $ordenCanonica->id,
                    'estado' => 'cancelado',
                    'fecha_entrega' => null,
                ]);

                if ($estadoAnterior !== 'cancelado') {
                    $ordenOrigen->historialEstados()->create([
                        'user_id' => $actor->id,
                        'estado_anterior' => $estadoAnterior,
                        'estado_nuevo' => 'cancelado',
                        'comentario' => "Reconciliación histórica {$caseKey}; orden canónica {$ordenCanonica->folio}.",
                    ]);
                }
            }

            $modelosDespues = $this->cargarModelos($definicion, lock: false);
            $snapshotDespues = $this->capturarSnapshot(
                $modelosDespues,
                $plan['manual_refaction_deduplications'],
            );
            $auditoria = ReconciliacionCotizacionOrden::create([
                'case_key' => $caseKey,
                'fingerprint' => $fingerprint,
                'cutoff_commit' => config('historical_quote_reconciliation.cutoff.commit'),
                'cutoff_at' => $this->cutoff(),
                'cotizacion_canonica_id' => $cotizacionCanonica->id,
                'orden_canonica_id' => $ordenCanonica->id,
                'aplicado_por_user_id' => $actor->id,
                'cotizaciones_origen_ids' => $cotizacionesOrigen->modelKeys(),
                'ordenes_origen_ids' => $ordenesOrigen->modelKeys(),
                'snapshot_antes' => $snapshotAntes,
                'snapshot_despues' => $snapshotDespues,
                'aplicado_at' => now(),
            ]);

            return ['aplicada' => true, 'id' => $auditoria->id, 'plan' => $plan];
        }, 3);
    }

    /** @return array<string, mixed> */
    private function construirPlan(
        string $caseKey,
        array $definicion,
        bool $lock = false,
        bool $ignorarAuditoria = false,
    ): array {
        if (! $ignorarAuditoria && $this->tieneEsquemaAuditoria()) {
            $aplicada = ReconciliacionCotizacionOrden::query()->where('case_key', $caseKey)->first();
            if ($aplicada) {
                return [
                    'key' => $caseKey,
                    'status' => 'applied',
                    'reasons' => ['Ya existe una auditoría estructurada para este caso.'],
                    'fingerprint' => $aplicada->fingerprint,
                    'canonical_quote' => $aplicada->cotizacionCanonica?->folio,
                    'canonical_order' => $aplicada->ordenCanonica?->folio,
                    'source_quotes' => $aplicada->cotizaciones_origen_ids,
                    'source_orders' => $aplicada->ordenes_origen_ids,
                    'provisional_services_to_delete' => 0,
                    'manual_refactions_preserved' => 0,
                    'manual_refactions_to_deduplicate' => 0,
                    'manual_refaction_deduplications' => [],
                    'projected_quote_total' => null,
                    'projected_order_total' => null,
                ];
            }
        }

        $modelos = $this->cargarModelos($definicion, $lock);
        if ($modelos['missing'] !== []) {
            return [
                'key' => $caseKey,
                'status' => 'missing',
                'reasons' => ['Faltan registros: '.implode(', ', $modelos['missing']).'.'],
                'fingerprint' => null,
                'canonical_quote' => $definicion['canonical_quote'],
                'canonical_order' => $definicion['canonical_order'],
                'source_quotes' => $definicion['source_quotes'],
                'source_orders' => $definicion['source_orders'],
                'provisional_services_to_delete' => 0,
                'manual_refactions_preserved' => 0,
                'manual_refactions_to_deduplicate' => 0,
                'manual_refaction_deduplications' => [],
                'projected_quote_total' => null,
                'projected_order_total' => null,
            ];
        }

        /** @var Cotizacion $cotizacionCanonica */
        $cotizacionCanonica = $modelos['canonical_quote'];
        /** @var OrdenServicio $ordenCanonica */
        $ordenCanonica = $modelos['canonical_order'];
        /** @var EloquentCollection<int, Cotizacion> $cotizacionesOrigen */
        $cotizacionesOrigen = $modelos['source_quotes'];
        /** @var EloquentCollection<int, OrdenServicio> $ordenes */
        $ordenes = $modelos['all_orders'];
        $razones = [];
        $status = 'ready';

        $ordenesConFinanzas = $ordenes->filter(fn (OrdenServicio $orden): bool => $orden->finanzas_generadas || $orden->movimientosFinancieros->isNotEmpty());
        $protegidaPorFinanzas = $ordenesConFinanzas->isNotEmpty();
        if ($ordenesConFinanzas->isNotEmpty()) {
            $status = 'protected';
            $razones[] = 'Órdenes excluidas por finanzas o movimientos: '.$ordenesConFinanzas->pluck('folio')->implode(', ').'.';
        }

        if ($ordenes->contains(fn (OrdenServicio $orden): bool => in_array($orden->estado, ['entregado', 'cancelado'], true))) {
            $status = 'blocked';
            $razones[] = 'Una orden participante ya está entregada o cancelada.';
        }

        $cotizaciones = collect([$cotizacionCanonica])->merge($cotizacionesOrigen);
        if ($cotizaciones->contains(fn (Cotizacion $cotizacion): bool => $cotizacion->estado !== 'aceptada')) {
            $status = 'blocked';
            $razones[] = 'Todas las cotizaciones participantes deben permanecer aceptadas.';
        }
        if ((float) $cotizacionCanonica->descuento !== 0.0) {
            $status = 'blocked';
            $razones[] = 'La cotización canónica tiene descuento y requiere decidir cómo representarlo en la orden.';
        }
        if ($cotizacionesOrigen->contains(fn (Cotizacion $cotizacion): bool => (float) $cotizacion->descuento !== 0.0 || (float) $cotizacion->anticipo !== 0.0)) {
            $status = 'blocked';
            $razones[] = 'Una cotización adicional tiene descuento o anticipo y requiere decisión contable.';
        }

        if ($ordenes->contains(fn (OrdenServicio $orden): bool => $orden->cliente_id !== $ordenCanonica->cliente_id || $orden->equipo_id !== $ordenCanonica->equipo_id)
            || $cotizaciones->contains(fn (Cotizacion $cotizacion): bool => $cotizacion->cliente_id !== $ordenCanonica->cliente_id || $cotizacion->equipo_id !== $ordenCanonica->equipo_id)) {
            $status = 'blocked';
            $razones[] = 'Cliente o equipo no coincide entre todos los registros del caso.';
        }

        $idsCotizaciones = $cotizaciones->pluck('id');
        if ($ordenes->contains(fn (OrdenServicio $orden): bool => $orden->cotizacion_id !== null && ! $idsCotizaciones->contains($orden->cotizacion_id))) {
            $status = 'blocked';
            $razones[] = 'Una orden participante está vinculada a una cotización ajena al caso.';
        }

        $vinculosAjenos = OrdenServicio::query()
            ->whereIn('cotizacion_id', $idsCotizaciones)
            ->whereNotIn('id', $ordenes->modelKeys())
            ->exists();
        if ($vinculosAjenos) {
            $status = 'blocked';
            $razones[] = 'Una cotización participante está vinculada a una orden ajena al caso.';
        }

        $refaccionesManuales = $ordenCanonica->refacciones->whereNull('cotizacion_item_id');
        $deduplicaciones = $this->resolverDeduplicacionesRefacciones($definicion, $modelos);
        foreach ($deduplicaciones['reasons'] as $razon) {
            $status = 'blocked';
            $razones[] = $razon;
        }
        $idsRefaccionesAutorizadas = collect($deduplicaciones['authorized'])->pluck('manual_refaction_id');
        $refaccionesManualesPreservadas = $refaccionesManuales->whereNotIn('id', $idsRefaccionesAutorizadas);
        if ($refaccionesManualesPreservadas->isNotEmpty()) {
            $status = 'blocked';
            $razones[] = 'La orden canónica tiene refacciones manuales sin autorización explícita; no se eliminan ni asocian automáticamente.';
        }

        $itemIds = $cotizaciones->flatMap->items->pluck('id');
        $ordenIds = $ordenes->modelKeys();
        $trazasAjenas = OrdenServicioDetalle::query()
            ->whereIn('cotizacion_item_id', $itemIds)
            ->whereNotIn('orden_servicio_id', $ordenIds)
            ->exists()
            || OrdenServicioRefaccion::query()
                ->whereIn('cotizacion_item_id', $itemIds)
                ->whereNotIn('orden_servicio_id', $ordenIds)
                ->exists();
        if ($trazasAjenas) {
            $status = 'blocked';
            $razones[] = 'Existe una línea trazable de la cotización en una orden ajena al caso.';
        }

        if ($protegidaPorFinanzas) {
            $status = 'protected';
        }

        $serviciosProvisionales = $ordenes->sum(fn (OrdenServicio $orden): int => $this->esAnteriorAlCutoff($orden)
                ? $orden->detalles->whereNull('cotizacion_item_id')->count()
                : 0);
        $serviciosManualesPreservados = $ordenCanonica->created_at->greaterThanOrEqualTo($this->cutoff())
            ? $ordenCanonica->detalles->whereNull('cotizacion_item_id')->sum('subtotal')
            : 0;
        $subtotalCotizaciones = $cotizaciones->flatMap->items->sum('subtotal');
        $resumenCotizacion = $this->calcularCotizacion->resumen(
            $cotizaciones->flatMap->items->map->toArray()->all(),
            (float) $cotizacionCanonica->descuento,
            (float) $cotizacionCanonica->anticipo,
        );
        $totalOrdenProyectado = round(
            (float) $subtotalCotizaciones
            + (float) $serviciosManualesPreservados
            + (float) $refaccionesManualesPreservadas->sum('precio_total_cliente'),
            2,
        );
        $snapshot = $this->capturarSnapshot($modelos, $deduplicaciones['authorized']);
        $fingerprint = hash('sha256', json_encode([
            'version' => 1,
            'cutoff' => config('historical_quote_reconciliation.cutoff'),
            'definition' => $definicion,
            'snapshot' => $snapshot,
        ], JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));

        if ($razones === []) {
            $razones[] = 'Caso aplicable únicamente con --apply, --case, --fingerprint y --actor.';
        }

        return [
            'key' => $caseKey,
            'status' => $status,
            'reasons' => $razones,
            'fingerprint' => $fingerprint,
            'canonical_quote' => $cotizacionCanonica->folio,
            'canonical_order' => $ordenCanonica->folio,
            'source_quotes' => $cotizacionesOrigen->pluck('folio')->all(),
            'source_orders' => $modelos['source_orders']->pluck('folio')->all(),
            'provisional_services_to_delete' => $serviciosProvisionales,
            'manual_refactions_preserved' => $refaccionesManualesPreservadas->count(),
            'manual_refactions_to_deduplicate' => count($deduplicaciones['authorized']),
            'manual_refaction_deduplications' => $deduplicaciones['authorized'],
            'projected_quote_total' => $resumenCotizacion['total'],
            'projected_order_total' => $totalOrdenProyectado,
            'snapshot' => $snapshot,
            'models' => $modelos,
        ];
    }

    /** @return array<string, mixed> */
    private function cargarModelos(array $definicion, bool $lock): array
    {
        $cotizacionFolios = collect([$definicion['canonical_quote']])->merge($definicion['source_quotes'])->values();
        $ordenFolios = collect([$definicion['canonical_order']])->merge($definicion['source_orders'])->values();
        $cotizacionesQuery = Cotizacion::query()
            ->whereIn('folio', $cotizacionFolios)
            ->with('items')
            ->orderBy('id');
        $ordenesQuery = OrdenServicio::query()
            ->whereIn('folio', $ordenFolios)
            ->with(['detalles', 'refacciones', 'movimientosFinancieros'])
            ->orderBy('id');
        if ($lock) {
            $cotizacionesQuery->lockForUpdate();
            $ordenesQuery->lockForUpdate();
        }

        $cotizaciones = $cotizacionesQuery->get()->keyBy('folio');
        $ordenes = $ordenesQuery->get()->keyBy('folio');

        if ($lock) {
            foreach ($cotizaciones as $cotizacion) {
                $cotizacion->setRelation('items', $cotizacion->items()->orderBy('id')->lockForUpdate()->get());
            }
            foreach ($ordenes as $orden) {
                $orden->setRelation('detalles', $orden->detalles()->orderBy('id')->lockForUpdate()->get());
                $orden->setRelation('refacciones', $orden->refacciones()->orderBy('id')->lockForUpdate()->get());
                $orden->setRelation('movimientosFinancieros', $orden->movimientosFinancieros()->orderBy('id')->lockForUpdate()->get());
            }
        }

        $faltantes = $cotizacionFolios->reject(fn (string $folio): bool => $cotizaciones->has($folio))
            ->merge($ordenFolios->reject(fn (string $folio): bool => $ordenes->has($folio)))
            ->values()
            ->all();

        return [
            'missing' => $faltantes,
            'canonical_quote' => $cotizaciones->get($definicion['canonical_quote']),
            'canonical_order' => $ordenes->get($definicion['canonical_order']),
            'source_quotes' => new EloquentCollection(
                collect($definicion['source_quotes'])->map(fn (string $folio) => $cotizaciones->get($folio))->filter()->all(),
            ),
            'source_orders' => new EloquentCollection(
                collect($definicion['source_orders'])->map(fn (string $folio) => $ordenes->get($folio))->filter()->all(),
            ),
            'all_quotes' => new EloquentCollection($cotizaciones->values()->all()),
            'all_orders' => new EloquentCollection($ordenes->values()->all()),
        ];
    }

    /**
     * @param  array<string, mixed>  $modelos
     * @param  array<int, array<string, mixed>>  $deduplicaciones
     */
    private function capturarSnapshot(array $modelos, array $deduplicaciones = []): array
    {
        return [
            'manual_refaction_deduplications' => $deduplicaciones,
            'quotes' => $modelos['all_quotes']->sortBy('id')->map(fn (Cotizacion $cotizacion): array => [
                'id' => $cotizacion->id,
                'folio' => $cotizacion->folio,
                'canonical_id' => $cotizacion->getAttribute('cotizacion_canonica_id'),
                'cliente_id' => $cotizacion->cliente_id,
                'equipo_id' => $cotizacion->equipo_id,
                'estado' => $cotizacion->estado,
                'subtotal' => $cotizacion->subtotal,
                'descuento' => $cotizacion->descuento,
                'anticipo' => $cotizacion->anticipo,
                'total' => $cotizacion->total,
                'updated_at' => $cotizacion->updated_at?->toJSON(),
                'items' => $cotizacion->items->sortBy('id')->map(fn ($item): array => [
                    'id' => $item->id,
                    'cotizacion_id' => $item->cotizacion_id,
                    'origen_id' => $item->getAttribute('cotizacion_origen_id'),
                    'tipo' => $item->tipo,
                    'descripcion' => $item->descripcion,
                    'cantidad' => $item->cantidad,
                    'precio_unitario' => $item->precio_unitario,
                    'costo_unitario' => $item->costo_unitario,
                    'costo_total' => $item->costo_total,
                    'subtotal' => $item->subtotal,
                    'updated_at' => $item->updated_at?->toJSON(),
                ])->values()->all(),
            ])->values()->all(),
            'orders' => $modelos['all_orders']->sortBy('id')->map(fn (OrdenServicio $orden): array => [
                'id' => $orden->id,
                'folio' => $orden->folio,
                'cotizacion_id' => $orden->cotizacion_id,
                'canonical_id' => $orden->getAttribute('orden_canonica_id'),
                'cliente_id' => $orden->cliente_id,
                'equipo_id' => $orden->equipo_id,
                'estado' => $orden->estado,
                'finanzas_generadas' => (bool) $orden->finanzas_generadas,
                'created_at' => $orden->created_at?->toJSON(),
                'updated_at' => $orden->updated_at?->toJSON(),
                'movements' => $orden->movimientosFinancieros->sortBy('id')->map(fn ($movimiento): array => [
                    'id' => $movimiento->id,
                    'tipo' => $movimiento->tipo,
                    'categoria' => $movimiento->categoria,
                    'monto' => $movimiento->monto,
                ])->values()->all(),
                'services' => $orden->detalles->sortBy('id')->map(fn ($detalle): array => [
                    'id' => $detalle->id,
                    'item_id' => $detalle->cotizacion_item_id,
                    'descripcion' => $detalle->descripcion,
                    'cantidad' => $detalle->cantidad,
                    'precio_unitario' => $detalle->precio_unitario,
                    'costo_unitario' => $detalle->costo_unitario,
                    'subtotal' => $detalle->subtotal,
                    'updated_at' => $detalle->updated_at?->toJSON(),
                ])->values()->all(),
                'parts' => $orden->refacciones->sortBy('id')->map(fn ($refaccion): array => [
                    'id' => $refaccion->id,
                    'item_id' => $refaccion->cotizacion_item_id,
                    'descripcion' => $refaccion->descripcion,
                    'cantidad' => $refaccion->cantidad,
                    'precio_unitario' => $refaccion->precio_unitario_cliente,
                    'costo_unitario' => $refaccion->costo_unitario,
                    'costo_total' => $refaccion->costo_total,
                    'subtotal' => $refaccion->precio_total_cliente,
                    'notas' => $refaccion->notas,
                    'created_at' => $refaccion->created_at?->toJSON(),
                    'updated_at' => $refaccion->updated_at?->toJSON(),
                ])->values()->all(),
            ])->values()->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $definicion
     * @param  array<string, mixed>  $modelos
     * @return array{authorized: array<int, array<string, mixed>>, reasons: array<int, string>}
     */
    private function resolverDeduplicacionesRefacciones(array $definicion, array $modelos): array
    {
        $reglas = $definicion['manual_refaction_deduplications'] ?? [];
        if (! is_array($reglas)) {
            return [
                'authorized' => [],
                'reasons' => ['La configuración de deduplicaciones de refacciones no es válida.'],
            ];
        }

        /** @var Cotizacion $cotizacion */
        $cotizacion = $modelos['canonical_quote'];
        /** @var OrdenServicio $orden */
        $orden = $modelos['canonical_order'];
        $autorizadas = [];
        $razones = [];
        $idsRefacciones = [];
        $idsItems = [];
        $campos = [
            'canonical_order_id', 'canonical_order_folio', 'manual_refaction_id',
            'canonical_quote_id', 'canonical_quote_folio', 'cotizacion_item_id',
            'type', 'description', 'normalized_description', 'quantity', 'unit_price',
            'manual_unit_cost', 'manual_total_cost', 'expected_item_unit_cost',
            'expected_item_total_cost', 'confirmed_unit_cost', 'reason',
        ];

        foreach ($reglas as $indice => $regla) {
            if (! is_array($regla) || collect($campos)->contains(fn (string $campo): bool => ! array_key_exists($campo, $regla))) {
                $razones[] = 'La regla explícita de deduplicación #'.($indice + 1).' está incompleta.';

                continue;
            }

            $refaccionId = (int) $regla['manual_refaction_id'];
            $itemId = (int) $regla['cotizacion_item_id'];
            if (in_array($refaccionId, $idsRefacciones, true) || in_array($itemId, $idsItems, true)) {
                $razones[] = 'Una refacción manual o item de cotización aparece más de una vez en la autorización explícita.';

                continue;
            }
            $idsRefacciones[] = $refaccionId;
            $idsItems[] = $itemId;

            $refaccion = $orden->refacciones->firstWhere('id', $refaccionId);
            $item = $cotizacion->items->firstWhere('id', $itemId);
            $descripcion = (string) $regla['description'];
            $normalizada = (string) $regla['normalized_description'];
            $cantidad = (int) $regla['quantity'];
            $precio = (float) $regla['unit_price'];
            $costoManual = (float) $regla['manual_unit_cost'];
            $costoManualTotal = (float) $regla['manual_total_cost'];
            $costoItemEsperado = (float) $regla['expected_item_unit_cost'];
            $costoItemTotalEsperado = (float) $regla['expected_item_total_cost'];
            $costoConfirmado = (float) $regla['confirmed_unit_cost'];

            $valida = (int) $regla['canonical_order_id'] === $orden->id
                && (string) $regla['canonical_order_folio'] === $orden->folio
                && (int) $regla['canonical_quote_id'] === $cotizacion->id
                && (string) $regla['canonical_quote_folio'] === $cotizacion->folio
                && $regla['type'] === 'refaccion'
                && $regla['reason'] === 'user_confirmed_same_physical_part'
                && $refaccion !== null
                && $refaccion->orden_servicio_id === $orden->id
                && $refaccion->cotizacion_item_id === null
                && $item !== null
                && $item->cotizacion_id === $cotizacion->id
                && $item->tipo === 'refaccion'
                && $refaccion->descripcion === $descripcion
                && $item->descripcion === $descripcion
                && $this->normalizarDescripcion($refaccion->descripcion) === $normalizada
                && $this->normalizarDescripcion($item->descripcion) === $normalizada
                && $refaccion->cantidad === $cantidad
                && (int) $item->cantidad === $cantidad
                && $this->mismoImporte($refaccion->precio_unitario_cliente, $precio)
                && $this->mismoImporte($refaccion->precio_total_cliente, $cantidad * $precio)
                && $this->mismoImporte($item->precio_unitario, $precio)
                && $this->mismoImporte($item->subtotal, $cantidad * $precio)
                && $this->mismoImporte($refaccion->costo_unitario, $costoManual)
                && $this->mismoImporte($refaccion->costo_total, $costoManualTotal)
                && $this->mismoImporte($item->costo_unitario, $costoItemEsperado)
                && $this->mismoImporte($item->costo_total, $costoItemTotalEsperado)
                && $costoConfirmado >= 0
                && $this->mismoImporte($costoManual, $costoConfirmado)
                && $this->mismoImporte($costoManualTotal, $cantidad * $costoConfirmado);

            if (! $valida) {
                $razones[] = "La autorización explícita para la refacción manual #{$refaccionId} y el item #{$itemId} no coincide exactamente con los datos actuales.";

                continue;
            }

            $autorizadas[] = [
                ...$regla,
                'manual_refaction_id' => $refaccionId,
                'cotizacion_item_id' => $itemId,
                'deduplicated_into_cotizacion_item_id' => $itemId,
                'quantity' => $cantidad,
                'unit_price' => $precio,
                'confirmed_unit_cost' => $costoConfirmado,
            ];
        }

        return ['authorized' => $autorizadas, 'reasons' => $razones];
    }

    /** @param array<int, array<string, mixed>> $deduplicaciones */
    private function aplicarDeduplicacionesRefacciones(
        array $deduplicaciones,
        Cotizacion $cotizacion,
        OrdenServicio $orden,
    ): void {
        foreach ($deduplicaciones as $deduplicacion) {
            $refaccion = OrdenServicioRefaccion::query()
                ->whereKey($deduplicacion['manual_refaction_id'])
                ->where('orden_servicio_id', $orden->id)
                ->whereNull('cotizacion_item_id')
                ->lockForUpdate()
                ->firstOrFail();
            $item = CotizacionItem::query()
                ->whereKey($deduplicacion['cotizacion_item_id'])
                ->where('cotizacion_id', $cotizacion->id)
                ->lockForUpdate()
                ->firstOrFail();
            $calculado = $this->calcularCotizacion->item([
                'tipo' => $item->tipo,
                'servicio_id' => $item->servicio_id,
                'descripcion' => $item->descripcion,
                'cantidad' => $item->cantidad,
                'precio_unitario' => $item->precio_unitario,
                'costo_unitario' => $deduplicacion['confirmed_unit_cost'],
            ]);

            $item->update([
                'costo_unitario' => $calculado['costo_unitario'],
                'costo_total' => $calculado['costo_total'],
            ]);
            $refaccion->delete();
        }
    }

    private function normalizarDescripcion(string $descripcion): string
    {
        return (string) str($descripcion)->ascii()->lower()->squish();
    }

    private function mismoImporte(mixed $actual, float $esperado): bool
    {
        return $actual !== null && abs((float) $actual - $esperado) < 0.001;
    }

    private function recalcularCotizacion(Cotizacion $cotizacion): void
    {
        $resumen = $this->calcularCotizacion->resumen(
            $cotizacion->items->map->toArray()->all(),
            (float) $cotizacion->descuento,
            (float) $cotizacion->anticipo,
        );
        $cotizacion->update($resumen);
    }

    private function cutoff(): CarbonImmutable
    {
        return CarbonImmutable::parse(config('historical_quote_reconciliation.cutoff.committed_at'));
    }

    private function esAnteriorAlCutoff(OrdenServicio $orden): bool
    {
        return $orden->created_at->lt($this->cutoff());
    }

    private function tieneEsquemaAuditoria(): bool
    {
        return Schema::hasTable('reconciliaciones_cotizacion_orden')
            && Schema::hasColumn('ordenes_servicio', 'orden_canonica_id')
            && Schema::hasColumn('cotizaciones', 'cotizacion_canonica_id')
            && Schema::hasColumn('cotizacion_items', 'cotizacion_origen_id');
    }

    private function asegurarEsquemaInstalado(): void
    {
        if (! $this->tieneEsquemaAuditoria()) {
            throw ValidationException::withMessages([
                'schema' => 'La migración de trazabilidad histórica todavía no está instalada. No se puede usar --apply.',
            ]);
        }
    }
}
