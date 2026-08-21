<?php

namespace App\Services;

use App\Actions\Cotizaciones\RegistrarAnticipoCotizacion;
use App\Actions\Ordenes\ValidarCostoTecnicoParaEntrega;
use App\Models\MovimientoFinanciero;
use App\Models\OrdenServicio;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GenerarFinanzasOrdenServicio
{
    public function __construct(private ValidarCostoTecnicoParaEntrega $validarCostoTecnico) {}

    public function generar(OrdenServicio $orden): void
    {
        DB::transaction(function () use ($orden): void {
            $orden = OrdenServicio::query()->lockForUpdate()->findOrFail($orden->id);

            if ($orden->orden_canonica_id !== null) {
                throw ValidationException::withMessages([
                    'estado_nuevo' => 'La orden fue consolidada mediante reconciliación histórica y nunca puede generar finanzas.',
                ]);
            }

            if ($orden->finanzas_generadas) {
                return;
            }

            if ($orden->usaCostosPorLinea()) {
                throw ValidationException::withMessages([
                    'estado_nuevo' => 'El modelo financiero costos_por_linea todavía no puede generar finanzas. Esta capacidad se habilitará en una fase posterior.',
                ]);
            }

            $this->validarCostoTecnico->ejecutar($orden);

            $movimientosExistentes = $orden->movimientosFinancieros()->lockForUpdate()->get();
            $movimientosIncompatibles = $movimientosExistentes->filter(fn (MovimientoFinanciero $movimiento): bool => $movimiento->tipo !== 'ingreso'
                || $movimiento->categoria !== RegistrarAnticipoCotizacion::CATEGORIA
                || $orden->cotizacion_id === null
                || (int) $movimiento->cotizacion_id !== (int) $orden->cotizacion_id
            );

            if ($movimientosIncompatibles->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'estado_nuevo' => 'La orden ya tiene movimientos financieros asociados. Revisa su historial antes de continuar.',
                ]);
            }

            $orden->loadMissing(['cotizacion', 'partnerRecepcion', 'partnerTecnico', 'detalles', 'refacciones']);
            $totalCliente = (float) $orden->total_cliente;
            $comisionLogistica = (float) ($orden->partnerRecepcion?->comision_fija ?? 0);
            $costoTecnico = (float) ($orden->costo_tecnico ?? 0);
            $totalCostoRefacciones = (float) $orden->refacciones->sum('costo_total');
            $totalCostoServicios = (float) $orden->detalles->sum('costo_total');
            $totalAnticipos = round((float) $movimientosExistentes->sum('monto'), 2);
            $saldo = round($totalCliente - $totalAnticipos, 2);

            if ($orden->cotizacion
                && abs((float) $orden->cotizacion->anticipo - $totalAnticipos) >= 0.01) {
                throw ValidationException::withMessages([
                    'estado_nuevo' => 'El anticipo indicado en la cotización ($'.number_format((float) $orden->cotizacion->anticipo, 2).') no coincide con los ingresos de anticipo registrados ($'.number_format($totalAnticipos, 2).'). Reconcilia el cobro antes de entregar.',
                ]);
            }

            if ($saldo < 0) {
                throw ValidationException::withMessages([
                    'estado_nuevo' => 'Los anticipos registrados ($'.number_format($totalAnticipos, 2).') superan el total de la orden ($'.number_format($totalCliente, 2).'). Corrige la inconsistencia antes de entregar.',
                ]);
            }

            if ($saldo > 0) {
                $this->crearMovimiento($orden, 'ingreso', 'reparacion', $saldo, null, 'Saldo de orden '.$orden->folio);
            }

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
                if ($refaccion->costo_total === null || (float) $refaccion->costo_total <= 0) {
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
                if ($detalle->costo_total === null || (float) $detalle->costo_total <= 0) {
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
                'costos_incompletos' => $orden->detalles->contains(fn ($detalle): bool => $detalle->costo_total === null)
                    || $orden->refacciones->contains(fn ($refaccion): bool => $refaccion->costo_total === null)
                    || ($orden->partner_tecnico_id !== null && $orden->costo_tecnico === null),
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
            'cotizacion_id' => $orden->cotizacion_id,
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
