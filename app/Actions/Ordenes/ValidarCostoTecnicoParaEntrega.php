<?php

namespace App\Actions\Ordenes;

use App\Exceptions\CostoTecnicoPendienteException;
use App\Models\OrdenServicio;

class ValidarCostoTecnicoParaEntrega
{
    public function ejecutar(OrdenServicio $orden): void
    {
        if ($orden->usaCostosPorLinea()) {
            $orden->loadMissing(['detalles', 'refacciones']);

            $comisionRecepcion = $orden->comision_recepcion === null ? null : (float) $orden->comision_recepcion;
            $hayServicioSinCosto = $orden->detalles->contains(fn ($detalle): bool => $detalle->costo_total === null);
            $hayRefaccionSinCosto = $orden->refacciones->contains(fn ($refaccion): bool => $refaccion->costo_total === null);

            // Recalcula sobre los datos actuales bajo lock: el booleano
            // costos_incompletos persistido es solo un snapshot para UI y
            // puede quedar stale si las líneas cambiaron después del último
            // recálculo.
            $incompleto = PoliticaCostosOrdenServicio::costosIncompletos(
                $orden->modelo_financiero,
                $orden->tipo_recepcion,
                tienePartnerTecnico: false,
                costoTecnico: null,
                comisionRecepcion: $comisionRecepcion,
                hayServicioSinCosto: $hayServicioSinCosto,
                hayRefaccionSinCosto: $hayRefaccionSinCosto,
            );

            if ($incompleto) {
                $comisionPendiente = PoliticaCostosOrdenServicio::requiereComisionRecepcion($orden->modelo_financiero, $orden->tipo_recepcion)
                    && $comisionRecepcion === null;

                $partes = [];

                if ($comisionPendiente) {
                    $partes[] = 'Confirma la comisión de recepción antes de entregar esta orden. Puedes registrar $0 si no hubo comisión.';
                }

                if ($hayServicioSinCosto && $hayRefaccionSinCosto) {
                    $partes[] = 'Hay servicios y refacciones con costo interno pendiente.';
                } elseif ($hayServicioSinCosto) {
                    $partes[] = 'Hay servicios con costo interno pendiente.';
                } elseif ($hayRefaccionSinCosto) {
                    $partes[] = 'Hay refacciones con costo interno pendiente.';
                }

                throw new CostoTecnicoPendienteException(implode(' ', $partes));
            }

            return;
        }

        if ($orden->partner_tecnico_id !== null && $orden->costo_tecnico === null) {
            throw new CostoTecnicoPendienteException(
                'Confirma el costo técnico antes de entregar la orden. Escribe 0 si Fixop no cobrará este trabajo.',
            );
        }
    }
}
