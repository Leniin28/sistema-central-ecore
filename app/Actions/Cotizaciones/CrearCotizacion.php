<?php

namespace App\Actions\Cotizaciones;

use App\Models\Cliente;
use App\Models\Cotizacion;
use App\Models\Equipo;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class CrearCotizacion
{
    public function __construct(
        private CalcularTotalesCotizacion $calculadora,
        private VincularCotizacionAOrden $vincularOrden,
        private RegistrarAnticipoCotizacion $registrarAnticipo,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $items
     * @param  User|null  $actor  Null when invoked from the internal API.
     */
    public function ejecutar(array $data, array $items, ?User $actor): Cotizacion
    {
        return DB::transaction(function () use ($data, $items, $actor): Cotizacion {
            if ($actor && ! $actor->isAdmin() && ! $actor->hasRole('socio_logistico')) {
                abort(403);
            }

            $items = $this->normalizarCostosInternos($items, $actor);

            if (! empty($data['external_id'])) {
                $existente = Cotizacion::query()->where('external_id', $data['external_id'])->first();

                if ($existente) {
                    return $existente;
                }
            }

            $cliente = $this->resolverCliente($data);
            $equipo = $this->resolverEquipo($data, $cliente);
            $recepcion = $this->resolverRecepcion($data);
            $resumen = $this->calculadora->resumen(
                $items,
                (float) ($data['descuento'] ?? 0),
                (float) ($data['anticipo'] ?? 0),
            );

            $cotizacion = Cotizacion::create([
                'folio' => $this->generarFolio(),
                'cliente_id' => $cliente->id,
                'equipo_id' => $equipo?->id,
                'creado_por_user_id' => $actor?->id,
                'partner_id' => $actor?->hasRole('socio_logistico') ? $actor->partner_id : null,
                'fecha' => $data['fecha'] ?? today(),
                'vigencia' => $data['vigencia'] ?? null,
                'estado' => 'borrador',
                ...$recepcion,
                ...$resumen,
                'notas' => $data['notas'] ?? null,
                'external_id' => $data['external_id'] ?? null,
            ]);

            foreach ($items as $item) {
                $cotizacion->items()->create($this->calculadora->item($item));
            }

            if (array_key_exists('orden_servicio_id', $data) && $data['orden_servicio_id'] !== null) {
                if (! $actor) {
                    throw ValidationException::withMessages([
                        'orden_servicio_id' => 'La vinculación con una orden existente requiere un administrador autenticado.',
                    ]);
                }

                $this->vincularOrden->vincular($cotizacion, (int) $data['orden_servicio_id'], $actor);
            }

            $this->registrarAnticipo->registrarCambio(
                $cotizacion,
                0,
                (float) $cotizacion->anticipo,
                $actor,
            );

            Log::info('Cotización creada', [
                'cotizacion_id' => $cotizacion->id,
                'folio' => $cotizacion->folio,
                'cliente_id' => $cliente->id,
                'user_id' => $actor?->id,
                'origen' => $actor ? 'web' : 'api_interna',
            ]);

            return $cotizacion->fresh(['items', 'cliente', 'equipo']);
        });
    }

    /**
     * Normalize the reception snapshot stored on the quote. The address is
     * kept on the quote itself so it survives later client changes.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function resolverRecepcion(array $data): array
    {
        $tipo = $data['tipo_recepcion'] ?? 'en_negocio';
        $direccion = $data['direccion_recepcion']
            ?? ($data['cliente']['direccion'] ?? null);

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
    private function resolverCliente(array $data): Cliente
    {
        if (! empty($data['cliente_id'])) {
            return Cliente::query()->findOrFail($data['cliente_id']);
        }

        $nuevo = $data['cliente'] ?? [];

        if (blank($nuevo['nombre'] ?? null)) {
            throw ValidationException::withMessages([
                'cliente_id' => 'Debes seleccionar un cliente existente o proporcionar los datos de uno nuevo.',
            ]);
        }

        return Cliente::create([
            'nombre' => $nuevo['nombre'],
            'telefono' => $nuevo['telefono'] ?? 'Sin teléfono',
            'tipo_cliente' => $nuevo['tipo_cliente'] ?? 'mantenimiento',
            'notas' => $nuevo['direccion'] ?? null,
        ]);
    }

    /** @param array<string, mixed> $data */
    private function resolverEquipo(array $data, Cliente $cliente): ?Equipo
    {
        if (! empty($data['equipo_id'])) {
            $equipo = Equipo::query()->findOrFail($data['equipo_id']);

            if ($equipo->cliente_id !== $cliente->id) {
                throw ValidationException::withMessages([
                    'equipo_id' => 'El equipo seleccionado no pertenece al cliente seleccionado.',
                ]);
            }

            return $equipo;
        }

        $nuevo = $data['equipo'] ?? [];

        if (blank($nuevo['tipo_equipo'] ?? null)) {
            return null;
        }

        return Equipo::create([
            'cliente_id' => $cliente->id,
            'tipo_equipo' => $nuevo['tipo_equipo'],
            'marca' => $nuevo['marca'] ?? 'Sin marca',
            'modelo' => $nuevo['modelo'] ?? null,
            'numero_serie' => $nuevo['numero_serie'] ?? null,
        ]);
    }

    private function generarFolio(): string
    {
        $fecha = now()->format('Ymd');
        $ultimoFolio = Cotizacion::query()
            ->where('folio', 'like', "COT-{$fecha}-%")
            ->lockForUpdate()
            ->orderByDesc('folio')
            ->value('folio');
        $consecutivo = $ultimoFolio ? ((int) substr($ultimoFolio, -4)) + 1 : 1;

        return 'COT-'.$fecha.'-'.str_pad((string) $consecutivo, 4, '0', STR_PAD_LEFT);
    }

    /** @param array<int, array<string, mixed>> $items @return array<int, array<string, mixed>> */
    private function normalizarCostosInternos(array $items, ?User $actor): array
    {
        if ($actor?->isAdmin()) {
            return $items;
        }

        return array_map(function (array $item): array {
            // A non-admin must not set an internal cost, but an omitted cost is
            // unknown, not a known zero.
            $item['costo_unitario'] = null;

            return $item;
        }, $items);
    }
}
