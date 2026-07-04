<?php

namespace App\Actions\Cotizaciones;

use App\Models\Cotizacion;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class CambiarEstadoCotizacion
{
    public function ejecutar(Cotizacion $cotizacion, string $estadoNuevo, User $actor): Cotizacion
    {
        if (! in_array($estadoNuevo, Cotizacion::ESTADOS, true)) {
            throw ValidationException::withMessages([
                'estado' => 'El estado seleccionado no es válido.',
            ]);
        }

        if ($estadoNuevo === $cotizacion->estado) {
            return $cotizacion;
        }

        if (! $cotizacion->esEditable() && ! $actor->isAdmin()) {
            throw ValidationException::withMessages([
                'estado' => 'Solo un administrador puede cambiar una cotización aceptada, rechazada o vencida.',
            ]);
        }

        $estadoAnterior = $cotizacion->estado;
        $cotizacion->update(['estado' => $estadoNuevo]);

        Log::info('Cotización cambió de estado', [
            'cotizacion_id' => $cotizacion->id,
            'folio' => $cotizacion->folio,
            'estado_anterior' => $estadoAnterior,
            'estado_nuevo' => $estadoNuevo,
            'user_id' => $actor->id,
        ]);

        return $cotizacion;
    }
}
