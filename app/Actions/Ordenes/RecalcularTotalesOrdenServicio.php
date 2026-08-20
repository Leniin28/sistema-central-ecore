<?php

namespace App\Actions\Ordenes;

use App\Models\OrdenServicio;

class RecalcularTotalesOrdenServicio
{
    public function ejecutar(OrdenServicio $orden): void
    {
        $orden->load(['detalles', 'refacciones']);
        $totalServicios = (float) $orden->detalles->sum('subtotal');
        $totalRefacciones = (float) $orden->refacciones->sum('precio_total_cliente');
        $costoServicios = (float) $orden->detalles->sum('costo_total');
        $costoRefacciones = (float) $orden->refacciones->sum('costo_total');
        $totalCliente = round($totalServicios + $totalRefacciones, 2);
        $costosIncompletos = $orden->detalles->contains(fn ($detalle): bool => $detalle->costo_total === null)
            || $orden->refacciones->contains(fn ($refaccion): bool => $refaccion->costo_total === null)
            || ($orden->partner_tecnico_id !== null && $orden->costo_tecnico === null);

        $orden->update([
            'total_cliente' => $totalCliente,
            'utilidad_estimada' => round(
                $totalCliente
                - $costoServicios
                - $costoRefacciones
                - (float) $orden->costo_tecnico
                - (float) $orden->comision_logistica,
                2,
            ),
            'costos_incompletos' => $costosIncompletos,
        ]);
    }
}
