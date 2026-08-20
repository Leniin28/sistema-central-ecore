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
    public function __construct(
        private CalcularTotalesCotizacion $calculadora,
        private VincularCotizacionAOrden $vincularOrden,
        private SincronizarLineasCotizacionConOrden $sincronizarLineas,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $items
     */
    public function ejecutar(Cotizacion $cotizacion, array $data, array $items, User $actor): Cotizacion
    {
        return DB::transaction(function () use ($cotizacion, $data, $items, $actor): Cotizacion {
            $cotizacion = Cotizacion::query()->lockForUpdate()->findOrFail($cotizacion->id);

            if ($cotizacion->estado === 'aceptada') {
                return $this->actualizarAceptada($cotizacion, $data, $items, $actor);
            }

            if (! $cotizacion->esEditable()) {
                throw ValidationException::withMessages([
                    'estado' => 'Solo pueden editarse cotizaciones en borrador o enviadas.',
                ]);
            }

            if (! $actor->isAdmin()) {
                $costosExistentes = $cotizacion->items()->pluck('costo_unitario', 'id');
                $items = array_map(function (array $item) use ($costosExistentes): array {
                    $itemId = $item['id'] ?? null;
                    $item['costo_unitario'] = $itemId !== null && $costosExistentes->has($itemId)
                        ? $costosExistentes->get($itemId)
                        : null;

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

            if (array_key_exists('orden_servicio_id', $data)) {
                $ordenId = filled($data['orden_servicio_id'] ?? null)
                    ? (int) $data['orden_servicio_id']
                    : null;
                $this->vincularOrden->vincular($cotizacion, $ordenId, $actor);
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
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $items
     */
    private function actualizarAceptada(Cotizacion $cotizacion, array $data, array $items, User $actor): Cotizacion
    {
        abort_unless($actor->isAdmin(), 403);

        $orden = $cotizacion->ordenServicio()->lockForUpdate()->first();
        if (! $orden) {
            throw ValidationException::withMessages([
                'orden_servicio_id' => 'La cotización aceptada no tiene una orden vinculada y no puede editarse.',
            ]);
        }

        $this->vincularOrden->asegurarModificableParaCotizacion($orden, $cotizacion);
        $this->validarCamposCongelados($data, $cotizacion, $orden->id);

        $itemsExistentes = $cotizacion->items()
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
        $idsRecibidos = collect($items)
            ->pluck('id')
            ->filter(fn ($id): bool => filled($id))
            ->map(fn ($id): int => (int) $id);

        if ($idsRecibidos->duplicates()->isNotEmpty()) {
            throw ValidationException::withMessages([
                'items' => 'Un concepto existente no puede enviarse más de una vez.',
            ]);
        }

        foreach ($idsRecibidos as $itemId) {
            if (! $itemsExistentes->has($itemId)) {
                throw ValidationException::withMessages([
                    'items' => 'Uno de los conceptos enviados no pertenece a esta cotización.',
                ]);
            }
        }

        $itemsCalculados = array_map(fn (array $item): array => [
            'id' => filled($item['id'] ?? null) ? (int) $item['id'] : null,
            ...$this->calculadora->item($item),
        ], $items);
        $resumen = $this->calculadora->resumen(
            $itemsCalculados,
            (float) $cotizacion->descuento,
            (float) $cotizacion->anticipo,
        );
        $recepcion = $this->resolverRecepcion($data, $cotizacion);

        $cotizacion->update([
            'fecha' => $data['fecha'],
            'vigencia' => $data['vigencia'] ?? null,
            ...$recepcion,
            ...$resumen,
            'notas' => $data['notas'] ?? null,
        ]);

        $idsConservados = collect();
        foreach ($itemsCalculados as $item) {
            $itemId = $item['id'];
            unset($item['id']);

            if ($itemId !== null) {
                $itemsExistentes->get($itemId)->update($item);
                $idsConservados->push($itemId);

                continue;
            }

            $nuevo = $cotizacion->items()->create($item);
            $idsConservados->push($nuevo->id);
        }

        $itemsEliminados = $itemsExistentes->except($idsConservados->all());
        $this->sincronizarLineas->eliminarLineas($orden, $itemsEliminados);
        foreach ($itemsEliminados as $itemEliminado) {
            $itemEliminado->delete();
        }

        $cotizacion->unsetRelation('items');
        $this->sincronizarLineas->sincronizar($orden, $cotizacion);

        Log::info('Cotización aceptada sincronizada con su orden', [
            'cotizacion_id' => $cotizacion->id,
            'orden_servicio_id' => $orden->id,
            'user_id' => $actor->id,
        ]);

        return $cotizacion->fresh(['items', 'cliente', 'equipo', 'ordenServicio']);
    }

    /** @param array<string, mixed> $data */
    private function validarCamposCongelados(array $data, Cotizacion $cotizacion, int $ordenId): void
    {
        if ((int) $data['cliente_id'] !== (int) $cotizacion->cliente_id) {
            throw ValidationException::withMessages([
                'cliente_id' => 'El cliente de una cotización aceptada no puede cambiarse.',
            ]);
        }

        if ((int) ($data['equipo_id'] ?? 0) !== (int) ($cotizacion->equipo_id ?? 0)) {
            throw ValidationException::withMessages([
                'equipo_id' => 'El equipo de una cotización aceptada no puede cambiarse.',
            ]);
        }

        if ((int) ($data['orden_servicio_id'] ?? 0) !== $ordenId) {
            throw ValidationException::withMessages([
                'orden_servicio_id' => 'La orden vinculada a una cotización aceptada no puede cambiarse.',
            ]);
        }
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
