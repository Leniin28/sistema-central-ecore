<?php

namespace App\Actions\Cotizaciones;

use App\Models\Cotizacion;
use App\Models\Equipo;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class ActualizarCotizacion
{
    public function __construct(private CalcularTotalesCotizacion $calculadora) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $items
     */
    public function ejecutar(Cotizacion $cotizacion, array $data, array $items, User $actor): Cotizacion
    {
        return DB::transaction(function () use ($cotizacion, $data, $items, $actor): Cotizacion {
            $cotizacion = Cotizacion::query()->lockForUpdate()->findOrFail($cotizacion->id);

            if (! $cotizacion->esEditable()) {
                throw ValidationException::withMessages([
                    'estado' => 'Solo pueden editarse cotizaciones en borrador o enviadas.',
                ]);
            }

            $this->validarEquipo($data);
            $resumen = $this->calculadora->resumen(
                $items,
                (float) ($data['descuento'] ?? 0),
                (float) ($data['anticipo'] ?? 0),
            );

            $cotizacion->update([
                'cliente_id' => $data['cliente_id'],
                'equipo_id' => $data['equipo_id'] ?? null,
                'fecha' => $data['fecha'],
                'vigencia' => $data['vigencia'] ?? null,
                ...$resumen,
                'notas' => $data['notas'] ?? null,
            ]);

            $cotizacion->items()->delete();

            foreach ($items as $item) {
                $cotizacion->items()->create($this->calculadora->item($item));
            }

            Log::info('Cotización actualizada', [
                'cotizacion_id' => $cotizacion->id,
                'folio' => $cotizacion->folio,
                'user_id' => $actor->id,
            ]);

            return $cotizacion->fresh(['items', 'cliente', 'equipo']);
        });
    }

    /** @param array<string, mixed> $data */
    private function validarEquipo(array $data): void
    {
        if (empty($data['equipo_id'])) {
            return;
        }

        $valido = Equipo::query()
            ->whereKey($data['equipo_id'])
            ->where('cliente_id', $data['cliente_id'])
            ->exists();

        if (! $valido) {
            throw ValidationException::withMessages([
                'equipo_id' => 'El equipo seleccionado no pertenece al cliente seleccionado.',
            ]);
        }
    }
}
