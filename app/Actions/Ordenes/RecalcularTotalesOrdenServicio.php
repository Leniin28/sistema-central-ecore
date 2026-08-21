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

        $costoTecnico = $orden->costo_tecnico === null ? null : (float) $orden->costo_tecnico;
        $comisionRecepcion = $orden->comision_recepcion === null ? null : (float) $orden->comision_recepcion;

        $costosIncompletos = PoliticaCostosOrdenServicio::costosIncompletos(
            $orden->modelo_financiero,
            $orden->tipo_recepcion,
            $orden->partner_tecnico_id !== null,
            $costoTecnico,
            $comisionRecepcion,
            $orden->detalles->contains(fn ($detalle): bool => $detalle->costo_total === null),
            $orden->refacciones->contains(fn ($refaccion): bool => $refaccion->costo_total === null),
        );
        $orden->update([
            'total_cliente' => $totalCliente,
            'utilidad_estimada' => PoliticaCostosOrdenServicio::utilidad(
                $orden->modelo_financiero,
                $totalCliente,
                $costoServicios,
                $costoRefacciones,
                (float) $orden->comision_logistica,
                $comisionRecepcion,
                $costoTecnico,
            ),
            'costos_incompletos' => $costosIncompletos,
        ]);
    }
}
