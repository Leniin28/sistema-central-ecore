<?php

namespace App\Actions\Ordenes;

use App\Actions\Cotizaciones\CalcularTotalesCotizacion;
use App\Models\CotizacionItem;
use App\Models\OrdenServicio;
use App\Models\OrdenServicioDetalle;
use App\Models\OrdenServicioRefaccion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ActualizarCostosInternosOrdenServicio
{
    public function __construct(
        private CalcularTotalesOrdenServicio $calculadoraOrden,
        private CalcularTotalesCotizacion $calculadoraCotizacion,
        private RecalcularTotalesOrdenServicio $recalcularTotales,
    ) {}

    /**
     * @param  array<int, array{id: mixed, costo_unitario: mixed}>  $servicios
     * @param  array<int, array{id: mixed, costo_unitario: mixed}>  $refacciones
     */
    public function ejecutar(
        OrdenServicio $orden,
        array $servicios,
        array $refacciones,
        User $actor,
    ): OrdenServicio {
        abort_unless($actor->isAdmin(), 403);

        return DB::transaction(function () use ($orden, $servicios, $refacciones): OrdenServicio {
            $orden = OrdenServicio::query()->lockForUpdate()->findOrFail($orden->id);
            $this->asegurarModificable($orden);

            $servicios = $this->normalizarCambios($servicios, 'costos_servicios');
            $refacciones = $this->normalizarCambios($refacciones, 'costos_refacciones');

            $lineasServicio = $this->bloquearServicios($orden, array_keys($servicios));
            $lineasRefaccion = $this->bloquearRefacciones($orden, array_keys($refacciones));
            $items = $this->bloquearItemsCotizacion($orden, $lineasServicio, $lineasRefaccion);

            foreach ($servicios as $id => $costoUnitario) {
                $linea = $lineasServicio->get($id);
                $calculada = $this->calculadoraOrden->detalle([
                    'servicio_id' => $linea->servicio_id,
                    'descripcion' => $linea->descripcion,
                    'cantidad' => $linea->cantidad,
                    'precio_unitario' => $linea->precio_unitario,
                    'costo_unitario' => $costoUnitario,
                    'notas' => $linea->notas,
                ]);
                $linea->update([
                    'costo_unitario' => $calculada['costo_unitario'],
                    'costo_total' => $calculada['costo_total'],
                ]);
                $this->sincronizarItem($linea->cotizacion_item_id, $calculada['costo_unitario'], $items);
            }

            foreach ($refacciones as $id => $costoUnitario) {
                $linea = $lineasRefaccion->get($id);
                $calculada = $this->calculadoraOrden->refaccion([
                    'descripcion' => $linea->descripcion,
                    'cantidad' => $linea->cantidad,
                    'precio_unitario_cliente' => $linea->precio_unitario_cliente,
                    'costo_unitario' => $costoUnitario,
                    'notas' => $linea->notas,
                ]);
                $linea->update([
                    'costo_unitario' => $calculada['costo_unitario'],
                    'costo_total' => $calculada['costo_total'],
                    'utilidad_refaccion' => $calculada['utilidad_refaccion'],
                ]);
                $this->sincronizarItem($linea->cotizacion_item_id, $calculada['costo_unitario'], $items);
            }

            $this->recalcularTotales->ejecutar($orden);

            return $orden->fresh(['detalles', 'refacciones']);
        });
    }

    private function asegurarModificable(OrdenServicio $orden): void
    {
        if ($orden->finanzas_generadas || $orden->estado === 'entregado') {
            throw ValidationException::withMessages([
                'costos' => 'Los costos no pueden modificarse porque las finanzas de la orden ya están cerradas.',
            ]);
        }

        if ($orden->orden_canonica_id !== null) {
            throw ValidationException::withMessages([
                'costos' => 'Una orden consolidada como duplicada no puede modificar costos internos.',
            ]);
        }

        if ($orden->estado === 'cancelado') {
            throw ValidationException::withMessages([
                'costos' => 'Una orden cancelada no puede modificar costos internos.',
            ]);
        }
    }

    /**
     * @param  array<int, array{id: mixed, costo_unitario: mixed}>  $cambios
     * @return array<int, float|null>
     */
    private function normalizarCambios(array $cambios, string $campo): array
    {
        $normalizados = [];

        foreach ($cambios as $cambio) {
            $id = filter_var($cambio['id'] ?? null, FILTER_VALIDATE_INT);
            if ($id === false || $id < 1 || array_key_exists($id, $normalizados)) {
                throw ValidationException::withMessages([
                    $campo => 'Cada línea debe identificarse una sola vez con un ID válido.',
                ]);
            }

            $costo = $cambio['costo_unitario'] ?? null;
            if ($costo === '') {
                $costo = null;
            }
            if ($costo !== null && (! is_numeric($costo) || (float) $costo < 0)) {
                throw ValidationException::withMessages([
                    $campo => 'Los costos internos deben estar vacíos o ser importes no negativos.',
                ]);
            }

            $normalizados[$id] = $costo === null ? null : round((float) $costo, 2);
        }

        return $normalizados;
    }

    /** @param list<int> $ids */
    private function bloquearServicios(OrdenServicio $orden, array $ids)
    {
        $lineas = OrdenServicioDetalle::query()
            ->where('orden_servicio_id', $orden->id)
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        if ($lineas->count() !== count($ids)) {
            throw ValidationException::withMessages([
                'costos_servicios' => 'Uno de los servicios no pertenece a esta orden.',
            ]);
        }

        return $lineas;
    }

    /** @param list<int> $ids */
    private function bloquearRefacciones(OrdenServicio $orden, array $ids)
    {
        $lineas = OrdenServicioRefaccion::query()
            ->where('orden_servicio_id', $orden->id)
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        if ($lineas->count() !== count($ids)) {
            throw ValidationException::withMessages([
                'costos_refacciones' => 'Una de las refacciones no pertenece a esta orden.',
            ]);
        }

        return $lineas;
    }

    private function bloquearItemsCotizacion(OrdenServicio $orden, $servicios, $refacciones)
    {
        $servicioIds = $servicios->pluck('cotizacion_item_id')->filter()->map(fn ($id): int => (int) $id);
        $refaccionIds = $refacciones->pluck('cotizacion_item_id')->filter()->map(fn ($id): int => (int) $id);
        $ids = $servicioIds->merge($refaccionIds)->unique()->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        $items = CotizacionItem::query()
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        $invalido = $items->count() !== $ids->count()
            || $items->contains(fn (CotizacionItem $item): bool => (int) $item->cotizacion_id !== (int) $orden->cotizacion_id)
            || $servicioIds->contains(fn (int $id): bool => $items->get($id)?->tipo !== 'servicio')
            || $refaccionIds->contains(fn (int $id): bool => $items->get($id)?->tipo === 'servicio');

        if ($invalido) {
            throw ValidationException::withMessages([
                'costos' => 'La trazabilidad entre la cotización y las líneas de la orden no es válida.',
            ]);
        }

        return $items;
    }

    private function sincronizarItem(?int $itemId, ?float $costoUnitario, $items): void
    {
        if ($itemId === null) {
            return;
        }

        $item = $items->get($itemId);
        $calculado = $this->calculadoraCotizacion->item([
            'tipo' => $item->tipo,
            'servicio_id' => $item->servicio_id,
            'descripcion' => $item->descripcion,
            'cantidad' => $item->cantidad,
            'precio_unitario' => $item->precio_unitario,
            'costo_unitario' => $costoUnitario,
        ]);
        $item->update([
            'costo_unitario' => $calculado['costo_unitario'],
            'costo_total' => $calculado['costo_total'],
        ]);
    }
}
