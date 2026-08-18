<?php

namespace App\Services;

use App\Models\MovimientoFinanciero;
use App\Models\OrdenServicio;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GenerarFinanzasOrdenServicio
{
    public function generar(OrdenServicio $orden): void
    {
        DB::transaction(function () use ($orden): void {
            $orden = OrdenServicio::query()->lockForUpdate()->findOrFail($orden->id);

            if ($orden->finanzas_generadas) {
                return;
            }

            if ($orden->movimientosFinancieros()->exists()) {
                throw ValidationException::withMessages([
                    'estado_nuevo' => 'La orden ya tiene movimientos financieros asociados. Revisa su historial antes de continuar.',
                ]);
            }

            $orden->loadMissing(['partnerRecepcion', 'partnerTecnico', 'detalles', 'refacciones']);
            $totalCliente = (float) $orden->total_cliente;
            $comisionLogistica = (float) ($orden->partnerRecepcion?->comision_fija ?? 0);
            $costoTecnico = (float) $orden->costo_tecnico;
            $totalCostoRefacciones = (float) $orden->refacciones->sum('costo_total');
            $totalCostoServicios = (float) $orden->detalles->sum('costo_total');

            $this->crearMovimiento($orden, 'ingreso', 'reparacion', $totalCliente, null, 'Ingreso por orden '.$orden->folio);

            if ($orden->partner_recepcion_id && $comisionLogistica > 0) {
                $this->crearMovimiento(
                    $orden,
                    'egreso',
                    'pago_socio_logistico',
                    $comisionLogistica,
                    $orden->partner_recepcion_id,
                    'Comisión logística por orden '.$orden->folio,
                );
            }

            if ($orden->partner_tecnico_id && $costoTecnico > 0) {
                $this->crearMovimiento(
                    $orden,
                    'egreso',
                    'pago_socio_tecnico',
                    $costoTecnico,
                    $orden->partner_tecnico_id,
                    'Pago técnico por orden '.$orden->folio,
                );
            }

            foreach ($orden->refacciones as $refaccion) {
                if ((float) $refaccion->costo_total <= 0) {
                    continue;
                }
                $this->crearMovimiento(
                    $orden,
                    'egreso',
                    'refaccion',
                    (float) $refaccion->costo_total,
                    null,
                    'Compra de refacción '.$refaccion->descripcion.' para orden '.$orden->folio,
                );
            }

            foreach ($orden->detalles as $detalle) {
                if ((float) $detalle->costo_total <= 0) {
                    continue;
                }

                $this->crearMovimiento(
                    $orden,
                    'egreso',
                    'servicio',
                    (float) $detalle->costo_total,
                    null,
                    'Costo interno de servicio '.$detalle->descripcion.' para orden '.$orden->folio,
                );
            }

            $orden->update([
                'comision_logistica' => $comisionLogistica,
                'utilidad_estimada' => $totalCliente - $comisionLogistica - $costoTecnico - $totalCostoServicios - $totalCostoRefacciones,
                'utilidad_neta' => $totalCliente - $comisionLogistica - $costoTecnico - $totalCostoServicios - $totalCostoRefacciones,
                'finanzas_generadas' => true,
            ]);
        });
    }

    private function crearMovimiento(
        OrdenServicio $orden,
        string $tipo,
        string $categoria,
        float $monto,
        ?int $partnerId,
        string $descripcion,
    ): void {
        MovimientoFinanciero::create([
            'orden_servicio_id' => $orden->id,
            'cliente_id' => $orden->cliente_id,
            'partner_id' => $partnerId,
            'tipo' => $tipo,
            'categoria' => $categoria,
            'monto' => $monto,
            'descripcion' => $descripcion,
            'fecha' => today(),
        ]);
    }
}
