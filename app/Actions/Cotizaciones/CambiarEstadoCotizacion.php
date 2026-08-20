<?php

namespace App\Actions\Cotizaciones;

use App\Models\Cotizacion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class CambiarEstadoCotizacion
{
    public function __construct(private ConvertirCotizacionEnOrden $convertirEnOrden) {}

    public function ejecutar(Cotizacion $cotizacion, string $estadoNuevo, User $actor): Cotizacion
    {
        if (! in_array($estadoNuevo, Cotizacion::ESTADOS, true)) {
            throw ValidationException::withMessages([
                'estado' => 'El estado seleccionado no es válido.',
            ]);
        }

        return DB::transaction(function () use ($cotizacion, $estadoNuevo, $actor): Cotizacion {
            $cotizacion = Cotizacion::query()->lockForUpdate()->findOrFail($cotizacion->id);
            $cotizacion->asegurarOperativa('estado');

            if (! $cotizacion->esEditable() && ! $actor->isAdmin()) {
                throw ValidationException::withMessages([
                    'estado' => 'Solo un administrador puede cambiar una cotización aceptada, rechazada o vencida.',
                ]);
            }

            if ($estadoNuevo === $cotizacion->estado) {
                if ($estadoNuevo === 'aceptada') {
                    $this->convertirEnOrden->ejecutar($cotizacion, [
                        // A logistics user can accept its quote, but only the
                        // system actor persists internal order costs.
                        ...($actor->isAdmin() ? ['actor' => $actor] : []),
                        'origen' => 'cotizacion-panel',
                        'origen_nota' => 'panel de cotizaciones',
                        'partner_recepcion_id' => $cotizacion->partner_id,
                        'recepcion' => [
                            'falla_reportada' => 'Servicio autorizado desde la cotización '.$cotizacion->folio,
                        ],
                    ]);
                }

                return $cotizacion;
            }

            if ($estadoNuevo === 'aceptada') {
                $estadoAnterior = $cotizacion->estado;
                $cotizacion->update(['estado' => 'aceptada']);

                $this->convertirEnOrden->ejecutar($cotizacion, [
                    ...($actor->isAdmin() ? ['actor' => $actor] : []),
                    'origen' => 'cotizacion-panel',
                    'origen_nota' => 'panel de cotizaciones',
                    'partner_recepcion_id' => $cotizacion->partner_id,
                    'recepcion' => [
                        'falla_reportada' => 'Servicio autorizado desde la cotización '.$cotizacion->folio,
                    ],
                ]);

                Log::info('Cotización aceptada y vinculada con su orden', [
                    'cotizacion_id' => $cotizacion->id,
                    'folio' => $cotizacion->folio,
                    'estado_anterior' => $estadoAnterior,
                    'user_id' => $actor->id,
                ]);

                return $cotizacion->fresh();
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
        });
    }
}
