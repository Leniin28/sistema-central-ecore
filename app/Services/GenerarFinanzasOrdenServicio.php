<?php

namespace App\Services;

use App\Actions\Cotizaciones\RegistrarAnticipoCotizacion;
use App\Actions\Ordenes\PoliticaCostosOrdenServicio;
use App\Actions\Ordenes\ValidarCostoTecnicoParaEntrega;
use App\Models\AjusteFinancieroOrden;
use App\Models\GeneracionFinancieraOrden;
use App\Models\MovimientoFinanciero;
use App\Models\OrdenServicio;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GenerarFinanzasOrdenServicio
{
    public const CATEGORIA_COMISION_RECEPCION = 'comision_recepcion';

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

            $this->validarCostoTecnico->ejecutar($orden);

            $movimientosExistentes = $orden->movimientosFinancieros()->lockForUpdate()->get();
            $lotesAnuladosIds = GeneracionFinancieraOrden::query()
                ->where('orden_servicio_id', $orden->id)
                ->whereHas('anulaciones', fn ($query) => $query->where('tipo', AjusteFinancieroOrden::TIPO_ANULACION_ENTREGA))
                ->pluck('id');

            $movimientosIncompatibles = $movimientosExistentes->filter(function (MovimientoFinanciero $movimiento) use ($orden, $lotesAnuladosIds): bool {
                $esAnticipoCompatible = $movimiento->tipo === 'ingreso'
                    && $movimiento->categoria === RegistrarAnticipoCotizacion::CATEGORIA
                    && $orden->cotizacion_id !== null
                    && (int) $movimiento->cotizacion_id === (int) $orden->cotizacion_id;

                // Movimientos (originales o compensatorios) de una entrega ya
                // anulada estructuralmente (FASE H.6) no bloquean una reentrega:
                // la posición financiera que dejaron ya fue neutralizada.
                $esDeLoteAnulado = $movimiento->generacion_financiera_orden_id !== null
                    && $lotesAnuladosIds->contains($movimiento->generacion_financiera_orden_id);

                return ! $esAnticipoCompatible && ! $esDeLoteAnulado;
            });

            if ($movimientosIncompatibles->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'estado_nuevo' => 'La orden ya tiene movimientos financieros asociados. Revisa su historial antes de continuar.',
                ]);
            }

            $orden->loadMissing(['cotizacion', 'partnerRecepcion', 'partnerTecnico', 'detalles', 'refacciones']);
            $totalCliente = (float) $orden->total_cliente;
            // Suma sólo anticipos genuinos: tras una reentrega (FASE H.6), los
            // movimientos existentes pueden incluir además el lote anulado y su
            // compensación, que deben quedar fuera de este cálculo (se anulan
            // entre sí, pero monto siempre es positivo sin importar tipo).
            $totalAnticipos = round((float) $movimientosExistentes
                ->filter(fn (MovimientoFinanciero $movimiento): bool => $movimiento->tipo === 'ingreso'
                    && $movimiento->categoria === RegistrarAnticipoCotizacion::CATEGORIA)
                ->sum('monto'), 2);
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

            $lote = GeneracionFinancieraOrden::create([
                'orden_servicio_id' => $orden->id,
                'tipo' => GeneracionFinancieraOrden::TIPO_ENTREGA,
                'actor_user_id' => auth()->id(),
                'modelo_financiero' => $orden->modelo_financiero,
                'fecha' => today(),
            ]);

            if ($orden->usaCostosPorLinea()) {
                $this->generarCostosPorLinea($orden, $saldo, $lote->id);
            } else {
                $this->generarLegacy($orden, $saldo, $lote->id);
            }
        });
    }

    private function generarLegacy(OrdenServicio $orden, float $saldo, int $loteId): void
    {
        $totalCliente = (float) $orden->total_cliente;
        $comisionLogistica = (float) ($orden->partnerRecepcion?->comision_fija ?? 0);
        $costoTecnico = (float) ($orden->costo_tecnico ?? 0);
        $totalCostoRefacciones = (float) $orden->refacciones->sum('costo_total');
        $totalCostoServicios = (float) $orden->detalles->sum('costo_total');

        if ($saldo > 0) {
            $this->crearMovimiento($orden, 'ingreso', 'reparacion', $saldo, null, 'Saldo de orden '.$orden->folio, $loteId);
        }

        if ($orden->partner_recepcion_id && $comisionLogistica > 0) {
            $this->crearMovimiento(
                $orden,
                'egreso',
                'pago_socio_logistico',
                $comisionLogistica,
                $orden->partner_recepcion_id,
                'Comisión logística por orden '.$orden->folio,
                $loteId,
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
                $loteId,
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
                $loteId,
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
                $loteId,
            );
        }

        $costoTecnicoNullable = $orden->costo_tecnico === null ? null : (float) $orden->costo_tecnico;
        $utilidad = PoliticaCostosOrdenServicio::utilidad(
            $orden->modelo_financiero,
            $totalCliente,
            $totalCostoServicios,
            $totalCostoRefacciones,
            $comisionLogistica,
            null,
            $costoTecnicoNullable,
        );

        $orden->update([
            'comision_logistica' => $comisionLogistica,
            'utilidad_estimada' => $utilidad,
            'utilidad_neta' => $utilidad,
            'costos_incompletos' => PoliticaCostosOrdenServicio::costosIncompletos(
                $orden->modelo_financiero,
                $orden->partner_tecnico_id !== null,
                $costoTecnicoNullable,
                null,
                $orden->detalles->contains(fn ($detalle): bool => $detalle->costo_total === null),
                $orden->refacciones->contains(fn ($refaccion): bool => $refaccion->costo_total === null),
            ),
            'finanzas_generadas' => true,
        ]);
    }

    /**
     * costos_por_linea: solo los costos internos capturados por línea y la comisión
     * de recepción restan a la utilidad. costo_tecnico y comision_logistica se
     * ignoran por completo — no generan pago_socio_tecnico ni pago_socio_logistico.
     */
    private function generarCostosPorLinea(OrdenServicio $orden, float $saldo, int $loteId): void
    {
        // La lectura completa de líneas/comisión ya ocurrió en generar()
        // (ValidarCostoTecnicoParaEntrega recalcula sobre datos actuales, no
        // sobre el booleano costos_incompletos persistido), así que a este
        // punto no existe ninguna línea o comisión NULL sin detectar.
        if ($saldo > 0) {
            $this->crearMovimiento($orden, 'ingreso', 'reparacion', $saldo, null, 'Saldo de orden '.$orden->folio, $loteId);
        }

        $totalCostoServicios = 0.0;
        foreach ($orden->detalles as $detalle) {
            $costoTotal = (float) $detalle->costo_total;
            $totalCostoServicios += $costoTotal;

            if ($costoTotal <= 0) {
                continue;
            }

            $this->crearMovimiento(
                $orden,
                'egreso',
                'servicio',
                $costoTotal,
                null,
                'Costo interno de servicio '.$detalle->descripcion.' para orden '.$orden->folio,
                $loteId,
            );
        }

        $totalCostoRefacciones = 0.0;
        foreach ($orden->refacciones as $refaccion) {
            $costoTotal = (float) $refaccion->costo_total;
            $totalCostoRefacciones += $costoTotal;

            if ($costoTotal <= 0) {
                continue;
            }

            $this->crearMovimiento(
                $orden,
                'egreso',
                'refaccion',
                $costoTotal,
                null,
                'Compra de refacción '.$refaccion->descripcion.' para orden '.$orden->folio,
                $loteId,
            );
        }

        $comisionRecepcionNullable = $orden->comision_recepcion === null ? null : (float) $orden->comision_recepcion;
        $comisionRecepcion = $comisionRecepcionNullable ?? 0.0;
        if ($comisionRecepcion > 0) {
            $descripcion = 'Comisión de recepción por orden '.$orden->folio
                .($orden->partnerRecepcion ? ' / '.$orden->partnerRecepcion->nombre : '')
                .($orden->nota_recepcion ? ' ('.$orden->nota_recepcion.')' : '');

            $this->crearMovimiento(
                $orden,
                'egreso',
                self::CATEGORIA_COMISION_RECEPCION,
                $comisionRecepcion,
                $orden->partner_recepcion_id,
                $descripcion,
                $loteId,
            );
        }

        $totalCliente = (float) $orden->total_cliente;
        $utilidad = PoliticaCostosOrdenServicio::utilidad(
            $orden->modelo_financiero,
            $totalCliente,
            $totalCostoServicios,
            $totalCostoRefacciones,
            0.0,
            $comisionRecepcionNullable,
            null,
        );

        $orden->update([
            'utilidad_estimada' => $utilidad,
            'utilidad_neta' => $utilidad,
            'costos_incompletos' => PoliticaCostosOrdenServicio::costosIncompletos(
                $orden->modelo_financiero,
                false,
                null,
                $comisionRecepcionNullable,
                $orden->detalles->contains(fn ($detalle): bool => $detalle->costo_total === null),
                $orden->refacciones->contains(fn ($refaccion): bool => $refaccion->costo_total === null),
            ),
            'finanzas_generadas' => true,
        ]);
    }

    private function crearMovimiento(
        OrdenServicio $orden,
        string $tipo,
        string $categoria,
        float $monto,
        ?int $partnerId,
        string $descripcion,
        int $loteId,
    ): void {
        MovimientoFinanciero::create([
            'orden_servicio_id' => $orden->id,
            'cotizacion_id' => $orden->cotizacion_id,
            'generacion_financiera_orden_id' => $loteId,
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
