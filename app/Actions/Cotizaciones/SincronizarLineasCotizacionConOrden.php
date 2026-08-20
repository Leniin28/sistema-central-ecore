<?php

namespace App\Actions\Cotizaciones;

use App\Actions\Ordenes\CalcularTotalesOrdenServicio;
use App\Actions\Ordenes\RecalcularTotalesOrdenServicio;
use App\Models\Cotizacion;
use App\Models\CotizacionItem;
use App\Models\OrdenServicio;
use App\Models\OrdenServicioDetalle;
use App\Models\OrdenServicioRefaccion;
use App\Models\Servicio;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class SincronizarLineasCotizacionConOrden
{
    public function __construct(
        private CalcularTotalesOrdenServicio $calculadora,
        private RecalcularTotalesOrdenServicio $recalcularTotales,
    ) {}

    /** @param Collection<int, CotizacionItem> $items */
    public function eliminarLineas(OrdenServicio $orden, Collection $items): void
    {
        $ids = $items->modelKeys();
        if ($ids === []) {
            return;
        }

        $this->bloquearYValidarLineas($orden, $ids);
        $orden->detalles()->whereIn('cotizacion_item_id', $ids)->delete();
        $orden->refacciones()->whereIn('cotizacion_item_id', $ids)->delete();
    }

    public function sincronizar(OrdenServicio $orden, Cotizacion $cotizacion): void
    {
        $cotizacion->load('items');
        $ids = $cotizacion->items->modelKeys();
        $this->bloquearYValidarLineas($orden, $ids);

        foreach ($cotizacion->items as $item) {
            if ($item->tipo === 'servicio') {
                $orden->refacciones()->where('cotizacion_item_id', $item->id)->delete();
                $orden->detalles()->updateOrCreate(
                    ['cotizacion_item_id' => $item->id],
                    $this->calculadora->detalle($this->mapearDetalle($item)),
                );

                continue;
            }

            $orden->detalles()->where('cotizacion_item_id', $item->id)->delete();
            $orden->refacciones()->updateOrCreate(
                ['cotizacion_item_id' => $item->id],
                $this->calculadora->refaccion($this->mapearRefaccion($cotizacion, $item)),
            );
        }

        $this->recalcularTotales->ejecutar($orden);
    }

    /** @param list<int> $itemIds */
    private function bloquearYValidarLineas(OrdenServicio $orden, array $itemIds): void
    {
        if ($itemIds === []) {
            return;
        }

        $detalles = OrdenServicioDetalle::query()
            ->whereIn('cotizacion_item_id', $itemIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        $refacciones = OrdenServicioRefaccion::query()
            ->whereIn('cotizacion_item_id', $itemIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($detalles->contains(fn ($linea): bool => $linea->orden_servicio_id !== $orden->id)
            || $refacciones->contains(fn ($linea): bool => $linea->orden_servicio_id !== $orden->id)) {
            throw ValidationException::withMessages([
                'cotizacion' => 'Una línea trazable de la cotización pertenece a otra orden.',
            ]);
        }

        if ($detalles->pluck('cotizacion_item_id')->intersect($refacciones->pluck('cotizacion_item_id'))->isNotEmpty()) {
            throw ValidationException::withMessages([
                'cotizacion' => 'Un concepto no puede existir como servicio y refacción al mismo tiempo.',
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function mapearDetalle(CotizacionItem $item): array
    {
        return [
            'servicio_id' => $this->resolverServicioExacto($item),
            'descripcion' => $item->descripcion,
            'cantidad' => (int) $item->cantidad,
            'precio_unitario' => (float) $item->precio_unitario,
            'costo_unitario' => $item->costo_unitario,
        ];
    }

    /** @return array<string, mixed> */
    private function mapearRefaccion(Cotizacion $cotizacion, CotizacionItem $item): array
    {
        return [
            'descripcion' => $item->descripcion,
            'cantidad' => (int) $item->cantidad,
            'precio_unitario_cliente' => (float) $item->precio_unitario,
            'costo_unitario' => $item->costo_unitario,
            'notas' => 'Tomado de la cotización '.$cotizacion->folio.' (tipo '.$item->tipo.').',
        ];
    }

    private function resolverServicioExacto(CotizacionItem $item): ?int
    {
        $descripcion = $this->normalizarDescripcion($item->descripcion);
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
}
