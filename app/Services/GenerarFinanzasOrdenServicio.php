<?php

namespace App\Services;

use App\Models\MovimientoFinanciero;
use App\Models\OrdenServicio;

class GenerarFinanzasOrdenServicio
{
    /**
     * Generate financial movements for a delivered service order.
     */
    public function generar(OrdenServicio $orden): void
    {
        if ($orden->finanzas_generadas) {
            return;
        }

        $orden->loadMissing(['partnerRecepcion', 'partnerTecnico', 'refacciones']);

        $totalCliente = (float) $orden->total_cliente;
        $comisionLogistica = 0.0;
        $costoTecnico = (float) $orden->costo_tecnico;
        $totalCostoRefacciones = (float) $orden->refacciones->sum('costo_total');

        MovimientoFinanciero::create([
            'orden_servicio_id' => $orden->id,
            'cliente_id' => $orden->cliente_id,
            'partner_id' => null,
            'tipo' => 'ingreso',
            'categoria' => 'reparacion',
            'monto' => $totalCliente,
            'descripcion' => 'Ingreso por orden '.$orden->folio,
            'fecha' => today(),
        ]);

        if ($orden->partner_recepcion_id) {
            $comisionLogistica = (float) ($orden->partnerRecepcion?->comision_fija ?? 0);

            if ($comisionLogistica > 0) {
                MovimientoFinanciero::create([
                    'orden_servicio_id' => $orden->id,
                    'cliente_id' => $orden->cliente_id,
                    'partner_id' => $orden->partner_recepcion_id,
                    'tipo' => 'egreso',
                    'categoria' => 'pago_socio_logistico',
                    'monto' => $comisionLogistica,
                    'descripcion' => 'Comisión logística por orden '.$orden->folio,
                    'fecha' => today(),
                ]);
            }
        }

        if ($orden->partner_tecnico_id && $costoTecnico > 0) {
            MovimientoFinanciero::create([
                'orden_servicio_id' => $orden->id,
                'cliente_id' => $orden->cliente_id,
                'partner_id' => $orden->partner_tecnico_id,
                'tipo' => 'egreso',
                'categoria' => 'pago_socio_tecnico',
                'monto' => $costoTecnico,
                'descripcion' => 'Pago técnico por orden '.$orden->folio,
                'fecha' => today(),
            ]);
        }

        foreach ($orden->refacciones as $refaccion) {
            $costoTotal = (float) $refaccion->costo_total;

            if ($costoTotal <= 0) {
                continue;
            }

            MovimientoFinanciero::create([
                'orden_servicio_id' => $orden->id,
                'cliente_id' => $orden->cliente_id,
                'partner_id' => null,
                'tipo' => 'egreso',
                'categoria' => 'refaccion',
                'monto' => $costoTotal,
                'descripcion' => 'Compra de refacción '.$refaccion->descripcion.' para orden '.$orden->folio,
                'fecha' => today(),
            ]);
        }

        $orden->update([
            'comision_logistica' => $comisionLogistica,
            'utilidad_neta' => $totalCliente - $comisionLogistica - $costoTecnico - $totalCostoRefacciones,
            'finanzas_generadas' => true,
        ]);
    }
}
