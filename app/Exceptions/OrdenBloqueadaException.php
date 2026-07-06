<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a change is attempted on a service order whose finances are already
 * closed (finanzas_generadas o estado entregado). The internal API maps it to 409.
 */
class OrdenBloqueadaException extends RuntimeException
{
}
