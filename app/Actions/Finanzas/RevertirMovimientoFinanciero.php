<?php

namespace App\Actions\Finanzas;

use App\Models\MovimientoFinanciero;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * V1 of financial-movement reversal: only manual/independent movements can be
 * reversed. A structured movement (anchored to an order and/or a quote via
 * orden_servicio_id/cotizacion_id -- the only safe, structural signal, never
 * categoria/descripcion) is never reversible from here, because undoing it
 * would leave finanzas_generadas/utilidad_neta/saldo/anticipo out of sync
 * with the OrdenServicio it belongs to.
 *
 * The original row is never edited or deleted. A reversal is a new
 * compensating movement of the opposite tipo and the same monto/categoria,
 * linked back via movimiento_original_id. One original can be reversed at
 * most once, and a reversion itself can never be reversed.
 */
class RevertirMovimientoFinanciero
{
    public function ejecutar(MovimientoFinanciero $original, string $motivo, User $actor): MovimientoFinanciero
    {
        if (! $actor->isAdmin()) {
            abort(403);
        }

        return DB::transaction(function () use ($original, $motivo, $actor): MovimientoFinanciero {
            /** @var MovimientoFinanciero $original */
            $original = MovimientoFinanciero::query()->lockForUpdate()->findOrFail($original->id);

            if ($original->esEstructurado()) {
                throw ValidationException::withMessages([
                    'movimiento' => 'Este movimiento pertenece a una operación estructurada y no puede revertirse desde Movimientos financieros.',
                ]);
            }

            if ($original->esReversion()) {
                throw ValidationException::withMessages([
                    'movimiento' => 'Un movimiento de reversión no puede volver a revertirse.',
                ]);
            }

            $yaRevertido = MovimientoFinanciero::query()
                ->where('movimiento_original_id', $original->id)
                ->lockForUpdate()
                ->exists();

            if ($yaRevertido) {
                throw ValidationException::withMessages([
                    'movimiento' => 'Este movimiento ya fue revertido.',
                ]);
            }

            $tipoOpuesto = $original->tipo === 'ingreso' ? 'egreso' : 'ingreso';

            try {
                return MovimientoFinanciero::create([
                    'orden_servicio_id' => null,
                    'cotizacion_id' => null,
                    'cliente_id' => $original->cliente_id,
                    'partner_id' => $original->partner_id,
                    'tipo' => $tipoOpuesto,
                    'categoria' => $original->categoria,
                    'monto' => $original->monto,
                    'descripcion' => 'Reversión de movimiento #'.$original->id.' ('.$motivo.')',
                    'fecha' => today(),
                    'movimiento_original_id' => $original->id,
                    'motivo_reversion' => $motivo,
                    'revertido_por_user_id' => $actor->id,
                ]);
            } catch (QueryException $exception) {
                // Defensa en profundidad detrás del lockForUpdate() de arriba:
                // si dos reversiones del mismo movimiento llegan a chocar con el
                // UNIQUE de movimiento_original_id, el segundo request recibe el
                // mismo mensaje amigable en vez de una QueryException cruda. El
                // UNIQUE y el lock no se debilitan -- esto sólo traduce el error.
                if ($exception->getCode() === '23000') {
                    throw ValidationException::withMessages([
                        'movimiento' => 'Este movimiento ya fue revertido.',
                    ]);
                }

                throw $exception;
            }
        });
    }
}
