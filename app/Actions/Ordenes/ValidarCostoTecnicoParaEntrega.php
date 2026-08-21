<?php

namespace App\Actions\Ordenes;

use App\Exceptions\CostoTecnicoPendienteException;
use App\Models\OrdenServicio;

class ValidarCostoTecnicoParaEntrega
{
    public function ejecutar(OrdenServicio $orden): void
    {
        if ($orden->usaCostosPorLinea()) {
            if ($orden->costos_incompletos) {
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
