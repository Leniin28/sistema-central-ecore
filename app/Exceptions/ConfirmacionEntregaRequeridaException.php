<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when the internal API tries to move an order to `entregado` without the
 * explicit confirm_final_delivery flag. Delivering closes the order and generates
 * real financial movements, so OpenClaw must confirm intentionally. Mapped to 409.
 */
class ConfirmacionEntregaRequeridaException extends RuntimeException
{
}
