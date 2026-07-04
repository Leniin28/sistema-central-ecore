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
        $precioUnitario = round((float) $item['precio_unitario'], 2);

        if (! in_array($tipo, CotizacionItem::TIPOS, true)) {
            throw ValidationException::withMessages([
                'items' => 'El tipo de concepto no es válido.',
            ]);
        }

        if ($cantidad < 1 || $precioUnitario < 0) {
            throw ValidationException::withMessages([
                'items' => 'Las cantidades deben ser mayores a cero y los precios no pueden ser negativos.',
            ]);
        }

        return [
            'tipo' => $tipo,
            'descripcion' => (string) $item['descripcion'],
            'cantidad' => $cantidad,
            'precio_unitario' => $precioUnitario,
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
