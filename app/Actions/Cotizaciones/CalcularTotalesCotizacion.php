<?php

namespace App\Actions\Cotizaciones;

use App\Models\CotizacionItem;
use Illuminate\Validation\ValidationException;

class CalcularTotalesCotizacion
{
    /** @param array<string, mixed> $item */
    public function item(array $item): array
    {
        $tipo = (string) ($item['tipo'] ?? 'otro');
        $cantidad = (int) $item['cantidad'];
        $descripcion = trim((string) ($item['descripcion'] ?? ''));
        $precioUnitario = round((float) $item['precio_unitario'], 2);
        $costoUnitario = round((float) ($item['costo_unitario'] ?? 0), 2);

        if (! in_array($tipo, CotizacionItem::TIPOS, true)) {
            throw ValidationException::withMessages([
                'items' => 'El tipo de concepto no es válido.',
            ]);
        }

        if ($descripcion === '' || $cantidad < 1 || $precioUnitario < 0 || $costoUnitario < 0) {
            throw ValidationException::withMessages([
                'items' => 'Cada concepto requiere descripción, cantidad mayor a cero y precios o costos no negativos.',
            ]);
        }

        return [
            'tipo' => $tipo,
            'servicio_id' => $item['servicio_id'] ?? null,
            'descripcion' => $descripcion,
            'cantidad' => $cantidad,
            'precio_unitario' => $precioUnitario,
            'costo_unitario' => $costoUnitario,
            'costo_total' => round($cantidad * $costoUnitario, 2),
            'subtotal' => round($cantidad * $precioUnitario, 2),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<string, float>
     */
    public function resumen(array $items, float $descuento = 0, float $anticipo = 0): array
    {
        $descuento = round($descuento, 2);
        $anticipo = round($anticipo, 2);
        $subtotal = round(collect($items)->sum(
            fn (array $item): float => (float) $this->item($item)['subtotal'],
        ), 2);

        if ($descuento < 0 || $anticipo < 0) {
            throw ValidationException::withMessages([
                'descuento' => 'El descuento y el anticipo no pueden ser negativos.',
            ]);
        }

        if ($descuento > $subtotal) {
            throw ValidationException::withMessages([
                'descuento' => 'El descuento no puede ser mayor al subtotal.',
            ]);
        }

        $total = round($subtotal - $descuento, 2);

        if ($anticipo > $total) {
            throw ValidationException::withMessages([
                'anticipo' => 'El anticipo no puede ser mayor al total.',
            ]);
        }

        return [
            'subtotal' => $subtotal,
            'descuento' => $descuento,
            'anticipo' => $anticipo,
            'total' => $total,
            'saldo' => round($total - $anticipo, 2),
        ];
    }
}
