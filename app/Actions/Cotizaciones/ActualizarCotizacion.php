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

            if (! $actor->isAdmin()) {
                $costosExistentes = $cotizacion->items()->pluck('costo_unitario', 'id');
                $items = array_map(function (array $item) use ($costosExistentes): array {
                    $item['costo_unitario'] = isset($costosExistentes[$item['id'] ?? null])
                        ? (float) $costosExistentes[$item['id']]
                        : 0;

                    return $item;
                }, $items);
            }

            $this->validarEquipo($data);
            $recepcion = $this->resolverRecepcion($data, $cotizacion);
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
                ...$recepcion,
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

    /**
     * Normalize the reception snapshot; keeps the stored value when the
     * request omits the fields so existing quotes do not lose context.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function resolverRecepcion(array $data, Cotizacion $cotizacion): array
    {
        $tipo = $data['tipo_recepcion'] ?? $cotizacion->tipo_recepcion ?? 'en_negocio';
        $direccion = $data['direccion_recepcion'] ?? $cotizacion->direccion_recepcion;

        if ($tipo === 'recogido_a_domicilio' && blank($direccion)) {
            throw ValidationException::withMessages([
                'direccion_recepcion' => 'Captura la dirección del cliente cuando el equipo se recoge a domicilio.',
            ]);
        }

        return [
            'tipo_recepcion' => $tipo,
            'direccion_recepcion' => $tipo === 'recogido_a_domicilio' ? $direccion : null,
        ];
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
