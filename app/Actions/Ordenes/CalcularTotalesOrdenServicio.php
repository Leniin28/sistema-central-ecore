<?php

namespace App\Actions\Ordenes;

use App\Models\OrdenServicio;
use Illuminate\Validation\ValidationException;

class CalcularTotalesOrdenServicio
{
    /** @param array<string, mixed> $detalle */
    public function detalle(array $detalle): array
    {
        $cantidad = (int) $detalle['cantidad'];
        $descripcion = trim((string) ($detalle['descripcion'] ?? ''));
        $precioUnitario = round((float) $detalle['precio_unitario'], 2);
        $costoUnitario = array_key_exists('costo_unitario', $detalle) && $detalle['costo_unitario'] !== null
            ? round((float) $detalle['costo_unitario'], 2)
            : null;

        if ($descripcion === '' || $cantidad < 1 || $precioUnitario < 0 || ($costoUnitario !== null && $costoUnitario < 0)) {
            throw ValidationException::withMessages([
                'servicios' => 'Cada servicio requiere descripción, cantidad mayor a cero y precios o costos no negativos.',
            ]);
        }

        return [
            'servicio_id' => $detalle['servicio_id'] ?? null,
            'descripcion' => $descripcion,
            'cantidad' => $cantidad,
            'precio_unitario' => $precioUnitario,
            'costo_unitario' => $costoUnitario,
            'costo_total' => $costoUnitario === null ? null : round($cantidad * $costoUnitario, 2),
            'subtotal' => round($cantidad * $precioUnitario, 2),
            'notas' => $detalle['notas'] ?? null,
        ];
    }

    /** @param array<string, mixed> $refaccion */
    public function refaccion(array $refaccion): array
    {
        $cantidad = (int) $refaccion['cantidad'];
        $costoUnitario = array_key_exists('costo_unitario', $refaccion) && $refaccion['costo_unitario'] !== null
            ? round((float) $refaccion['costo_unitario'], 2)
            : null;
        $precioUnitarioCliente = round((float) $refaccion['precio_unitario_cliente'], 2);
        $costoTotal = $costoUnitario === null ? null : round($cantidad * $costoUnitario, 2);
        $precioTotalCliente = round($cantidad * $precioUnitarioCliente, 2);

        return [
            'descripcion' => $refaccion['descripcion'],
            'cantidad' => $cantidad,
            'costo_unitario' => $costoUnitario,
            'precio_unitario_cliente' => $precioUnitarioCliente,
            'costo_total' => $costoTotal,
            'precio_total_cliente' => $precioTotalCliente,
            // The numeric value uses zero operationally; costo_total remains
            // NULL so callers can identify that this profitability is partial.
            'utilidad_refaccion' => round($precioTotalCliente - ($costoTotal ?? 0), 2),
            'notas' => $refaccion['notas'] ?? null,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $detalles
     * @param  array<int, array<string, mixed>>  $refacciones
     * @return array<string, float|bool>
     */
    public function resumen(
        array $detalles,
        array $refacciones,
        ?float $costoTecnico = null,
        float $comision = 0,
        bool $tienePartnerTecnico = false,
        string $modeloFinanciero = OrdenServicio::MODELO_FINANCIERO_LEGACY,
        ?float $comisionRecepcion = null,
    ): array {
        $detallesCalculados = collect($detalles)->map(fn (array $detalle): array => $this->detalle($detalle));
        $totalServicios = (float) $detallesCalculados->sum('subtotal');
        $costoServicios = (float) $detallesCalculados->sum('costo_total');
        $refaccionesCalculadas = collect($refacciones)->map(
            fn (array $refaccion): array => $this->refaccion($refaccion),
        );
        $totalRefacciones = (float) $refaccionesCalculadas->sum('precio_total_cliente');
        $costoRefacciones = (float) $refaccionesCalculadas->sum('costo_total');
        $totalCliente = round($totalServicios + $totalRefacciones, 2);

        $costosIncompletos = PoliticaCostosOrdenServicio::costosIncompletos(
            $modeloFinanciero,
            $tienePartnerTecnico,
            $costoTecnico,
            $comisionRecepcion,
            $detallesCalculados->contains(fn (array $detalle): bool => $detalle['costo_total'] === null),
            $refaccionesCalculadas->contains(fn (array $refaccion): bool => $refaccion['costo_total'] === null),
        );

        return [
            'total_servicios' => round($totalServicios, 2),
            'costo_servicios' => round($costoServicios, 2),
            'total_refacciones' => round($totalRefacciones, 2),
            'costo_refacciones' => round($costoRefacciones, 2),
            'total_cliente' => $totalCliente,
            'costo_total_interno' => round($costoServicios + $costoRefacciones, 2),
            'utilidad_estimada' => PoliticaCostosOrdenServicio::utilidad(
                $modeloFinanciero,
                $totalCliente,
                $costoServicios,
                $costoRefacciones,
                $comision,
                $comisionRecepcion,
                $costoTecnico,
            ),
            'costos_incompletos' => $costosIncompletos,
        ];
    }
}
