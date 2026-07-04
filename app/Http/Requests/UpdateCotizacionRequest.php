<?php

namespace App\Http\Requests;

class UpdateCotizacionRequest extends StoreCotizacionRequest
{
    // Reglas y autorización idénticas a la creación; la restricción de
    // estados editables vive en ActualizarCotizacion.
}
