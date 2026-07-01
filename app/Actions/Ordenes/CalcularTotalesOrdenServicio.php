<?php

namespace App\Actions\Ordenes;

class CalcularTotalesOrdenServicio
{
    /** @param array<string, mixed> $detalle */
    public function detalle(array $detalle): array
    {
        $cantidad = (int) $detalle['cantidad'];
        $precioUnitario = round((float) $detalle['precio_unitario'], 2);

        return [
            'servicio_id' => $detalle['servicio_id'],
            'cantidad' => $cantidad,
            'precio_unitario' => $precioUnitario,
            'subtotal' => round($cantidad * $precioUnitario, 2),
            'notas' => $detalle['notas'] ?? null,
        ];
    }

    /** @param array<string, mixed> $refaccion */
    public function refaccion(array $refaccion): array
    {
        $cantidad = (int) $refaccion['cantidad'];
        $costoUnitario = round((float) $refaccion['costo_unitario'], 2);
        $precioUnitarioCliente = round((float) $refaccion['precio_unitario_cliente'], 2);
        $costoTotal = round($cantidad * $costoUnitario, 2);
        $precioTotalCliente = round($cantidad * $precioUnitarioCliente, 2);

        return [
            'descripcion' => $refaccion['descripcion'],
            'cantidad' => $cantidad,
            'costo_unitario' => $costoUnitario,
            'precio_unitario_cliente' => $precioUnitarioCliente,
            'costo_total' => $costoTotal,
            'precio_total_cliente' => $precioTotalCliente,
            'utilidad_refaccion' => round($precioTotalCliente - $costoTotal, 2),
            'notas' => $refaccion['notas'] ?? null,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $detalles
     * @param  array<int, array<string, mixed>>  $refacciones
     * @return array<string, float>
     */
    public function resumen(array $detalles, array $refacciones, float $costoTecnico = 0, float $comision = 0): array
    {
        $totalServicios = collect($detalles)->sum(
            fn (array $detalle): float => (float) $this->detalle($detalle)['subtotal'],
        );
        $refaccionesCalculadas = collect($refacciones)->map(
            fn (array $refaccion): array => $this->refaccion($refaccion),
        );
        $totalRefacciones = (float) $refaccionesCalculadas->sum('precio_total_cliente');
        $costoRefacciones = (float) $refaccionesCalculadas->sum('costo_total');
        $totalCliente = round($totalServicios + $totalRefacciones, 2);

        return [
            'total_servicios' => round($totalServicios, 2),
            'total_refacciones' => round($totalRefacciones, 2),
            'costo_refacciones' => round($costoRefacciones, 2),
            'total_cliente' => $totalCliente,
            'utilidad_estimada' => round($totalCliente - $costoRefacciones - $costoTecnico - $comision, 2),
        ];
    }
}
