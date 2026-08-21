<?php

namespace App\Actions\Ordenes;

use App\Exceptions\CotizacionPendienteException;
use App\Models\OrdenServicio;

/**
 * Una orden sin cotización vinculada nunca se bloquea por esto (órdenes
 * creadas directamente no exigen cotización). Una orden con cotización
 * vinculada sólo puede entregarse si esa cotización ya está "aceptada".
 */
class ValidarCotizacionAceptadaParaEntrega
{
    public function ejecutar(OrdenServicio $orden): void
    {
        if ($orden->cotizacion_id === null) {
            return;
        }

        $orden->loadMissing('cotizacion');

        if ($orden->cotizacion?->estado !== 'aceptada') {
            throw new CotizacionPendienteException(
                'No puedes entregar esta orden porque la cotización vinculada todavía no ha sido aceptada.',
            );
        }
    }
}
