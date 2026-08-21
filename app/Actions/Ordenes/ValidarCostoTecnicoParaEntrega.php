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

            // Recalcula sobre los datos actuales bajo lock: el booleano
            // costos_incompletos persistido es solo un snapshot para UI y
            // puede quedar stale si las líneas cambiaron después del último
            // recálculo.
            $incompleto = PoliticaCostosOrdenServicio::costosIncompletos(
                $orden->modelo_financiero,
                tienePartnerTecnico: false,
                costoTecnico: null,
                comisionRecepcion: $comisionRecepcion,
                hayServicioSinCosto: $orden->detalles->contains(fn ($detalle): bool => $detalle->costo_total === null),
                hayRefaccionSinCosto: $orden->refacciones->contains(fn ($refaccion): bool => $refaccion->costo_total === null),
            );

            if ($incompleto) {
                throw new CostoTecnicoPendienteException(
                    'Completa la comisión de recepción y los costos de servicios/refacciones antes de entregar la orden.',
                );
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
