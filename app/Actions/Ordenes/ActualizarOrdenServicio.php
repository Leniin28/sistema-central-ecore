<?php

namespace App\Actions\Ordenes;

use App\Models\Equipo;
use App\Models\OrdenServicio;
use App\Models\Partner;
use App\Models\Servicio;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ActualizarOrdenServicio
{
    public function __construct(
        private CalcularTotalesOrdenServicio $calculadora,
        private RecalcularTotalesOrdenServicio $recalcularTotales,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $detalles
     * @param  array<int, array<string, mixed>>  $refacciones
     */
    public function ejecutar(OrdenServicio $orden, array $data, array $detalles, array $refacciones, User $actor): OrdenServicio
    {
        abort_unless($actor->isAdmin(), 403);

        return DB::transaction(function () use ($orden, $data, $detalles, $refacciones): OrdenServicio {
            $orden = OrdenServicio::query()->lockForUpdate()->findOrFail($orden->id);

            if ($orden->finanzas_generadas
                || $orden->estado === 'entregado'
                || $orden->estado === 'cancelado'
                || $orden->orden_canonica_id !== null) {
                throw ValidationException::withMessages([
                    'orden' => 'La orden cerrada o consolidada no puede modificarse.',
                ]);
            }

            $orden->load('cotizacion');
            if ($orden->cotizacion?->estado === 'aceptada'
                && ($orden->estado === 'cancelado' || $orden->tieneMovimientosFinancierosIncompatibles())) {
                throw ValidationException::withMessages([
                    'orden' => 'La orden vinculada está cerrada o tiene movimientos financieros y no puede modificarse.',
                ]);
            }

            if ($orden->cotizacion?->estado === 'aceptada'
                && ((int) $data['cliente_id'] !== (int) $orden->cliente_id
                    || (int) ($data['equipo_id'] ?? 0) !== (int) ($orden->equipo_id ?? 0))) {
                throw ValidationException::withMessages([
                    'orden' => 'El cliente y equipo de una orden con cotización aceptada no pueden cambiarse.',
                ]);
            }

            $this->validarAsignaciones($data);
            $detalles = $this->normalizarDetalles($detalles);

            if ($orden->usaCostosPorLinea()) {
                // costo_tecnico no participa financieramente ni se captura en el
                // formulario para costos_por_linea: no lo toques al guardar.
                unset($data['costo_tecnico']);
            } elseif ((int) ($orden->partner_tecnico_id ?? 0) !== (int) ($data['partner_tecnico_id'] ?? 0)) {
                $data['costo_tecnico'] = null;
            }

            $orden->update($data);
            $orden->detalles()->whereNull('cotizacion_item_id')->delete();
            $orden->refacciones()->whereNull('cotizacion_item_id')->delete();

            foreach ($detalles as $detalle) {
                $orden->detalles()->create($this->calculadora->detalle($detalle));
            }

            foreach ($refacciones as $refaccion) {
                $orden->refacciones()->create($this->calculadora->refaccion($refaccion));
            }

            $this->recalcularTotales->ejecutar($orden);

            return $orden->fresh();
        });
    }

    /** @param array<string, mixed> $data */
    private function validarAsignaciones(array $data): void
    {
        if (! empty($data['equipo_id'])) {
            $equipoValido = Equipo::query()
                ->whereKey($data['equipo_id'])
                ->where('cliente_id', $data['cliente_id'])
                ->exists();

            if (! $equipoValido) {
                throw ValidationException::withMessages(['equipo_id' => 'El equipo no pertenece al cliente.']);
            }
        }

        foreach ([
            'partner_recepcion_id' => 'logistico',
            'partner_tecnico_id' => 'tecnico',
        ] as $campo => $tipo) {
            if (empty($data[$campo])) {
                continue;
            }

            $valido = Partner::query()
                ->whereKey($data[$campo])
                ->where('tipo_socio', $tipo)
                ->where('activo', true)
                ->exists();

            if (! $valido) {
                throw ValidationException::withMessages([
                    $campo => 'El partner seleccionado no corresponde al tipo requerido o está inactivo.',
                ]);
            }
        }
    }

    /** @param array<int, array<string, mixed>> $detalles @return array<int, array<string, mixed>> */
    private function normalizarDetalles(array $detalles): array
    {
        $servicios = Servicio::query()
            ->whereIn('id', collect($detalles)->pluck('servicio_id')->filter())
            ->pluck('nombre', 'id');

        return array_map(function (array $detalle) use ($servicios): array {
            if (blank($detalle['descripcion'] ?? null) && ! empty($detalle['servicio_id'])) {
                $detalle['descripcion'] = $servicios[$detalle['servicio_id']] ?? null;
            }

            return $detalle;
        }, $detalles);
    }
}
