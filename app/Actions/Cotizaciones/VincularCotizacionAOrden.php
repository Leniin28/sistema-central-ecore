<?php

namespace App\Actions\Cotizaciones;

use App\Models\Cotizacion;
use App\Models\OrdenServicio;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class VincularCotizacionAOrden
{
    /** @var list<string> */
    private const ESTADOS_ACTIVOS = [
        'recibido',
        'en_diagnostico',
        'cotizacion_pendiente',
        'cotizacion_aprobada',
        'en_proceso',
        'en_fixop',
        'listo_para_entregar',
    ];

    /**
     * @return Collection<int, OrdenServicio>
     */
    public function elegibles(int $clienteId, int $equipoId, ?Cotizacion $cotizacion = null): Collection
    {
        return OrdenServicio::query()
            ->where('cliente_id', $clienteId)
            ->where('equipo_id', $equipoId)
            ->whereIn('estado', self::ESTADOS_ACTIVOS)
            ->where('finanzas_generadas', false)
            ->whereDoesntHave('movimientosFinancieros')
            ->where(function ($query) use ($cotizacion): void {
                $query->whereNull('cotizacion_id');

                if ($cotizacion?->exists) {
                    $query->orWhere('cotizacion_id', $cotizacion->id);
                }
            })
            ->latest('fecha_recepcion')
            ->get();
    }

    public function esElegibleParaNuevaCotizacion(OrdenServicio $orden): bool
    {
        return $orden->cliente_id !== null
            && $orden->equipo_id !== null
            && $orden->cotizacion_id === null
            && in_array($orden->estado, self::ESTADOS_ACTIVOS, true)
            && ! $orden->finanzas_generadas
            && ! $orden->movimientosFinancieros()->exists();
    }

    public function vincular(Cotizacion $cotizacion, ?int $ordenId, User $actor): ?OrdenServicio
    {
        if (! $actor->isAdmin()) {
            throw ValidationException::withMessages([
                'orden_servicio_id' => 'Solo un administrador puede vincular una cotización con una orden existente.',
            ]);
        }

        return DB::transaction(function () use ($cotizacion, $ordenId): ?OrdenServicio {
            $cotizacion = Cotizacion::query()->lockForUpdate()->findOrFail($cotizacion->id);
            $ordenActualId = OrdenServicio::query()
                ->where('cotizacion_id', $cotizacion->id)
                ->value('id');
            $ids = collect([$ordenActualId, $ordenId])
                ->filter()
                ->map(fn ($id): int => (int) $id)
                ->unique()
                ->sort()
                ->values();
            $ordenes = OrdenServicio::query()
                ->whereKey($ids)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $ordenActual = $ordenActualId ? $ordenes->get($ordenActualId) : null;

            if ($ordenId === null) {
                $ordenActual?->update(['cotizacion_id' => null]);

                return null;
            }

            $orden = $ordenes->get($ordenId);
            if (! $orden) {
                throw ValidationException::withMessages([
                    'orden_servicio_id' => 'La orden seleccionada no existe.',
                ]);
            }

            $this->asegurarElegible($orden, $cotizacion);

            if ($ordenActual && $ordenActual->isNot($orden)) {
                $ordenActual->update(['cotizacion_id' => null]);
            }

            if ($orden->cotizacion_id !== $cotizacion->id) {
                $orden->update(['cotizacion_id' => $cotizacion->id]);
            }

            return $orden->fresh();
        });
    }

    public function asegurarElegible(
        OrdenServicio $orden,
        Cotizacion $cotizacion,
        bool $exigirEquipo = true,
    ): void {
        if ($exigirEquipo && $cotizacion->equipo_id === null) {
            throw ValidationException::withMessages([
                'orden_servicio_id' => 'La cotización debe tener un equipo para vincularse con una orden existente.',
            ]);
        }

        if ((int) $orden->cliente_id !== (int) $cotizacion->cliente_id) {
            throw ValidationException::withMessages([
                'orden_servicio_id' => 'La orden seleccionada pertenece a otro cliente.',
            ]);
        }

        if ($cotizacion->equipo_id !== null && (int) $orden->equipo_id !== (int) $cotizacion->equipo_id) {
            throw ValidationException::withMessages([
                'orden_servicio_id' => 'La orden seleccionada pertenece a otro equipo.',
            ]);
        }

        if (! in_array($orden->estado, self::ESTADOS_ACTIVOS, true)) {
            throw ValidationException::withMessages([
                'orden_servicio_id' => 'La orden seleccionada no está en un estado activo que permita vincularla.',
            ]);
        }

        if ($orden->finanzas_generadas) {
            throw ValidationException::withMessages([
                'orden_servicio_id' => 'La orden seleccionada ya tiene sus finanzas generadas.',
            ]);
        }

        if ($orden->movimientosFinancieros()->exists()) {
            throw ValidationException::withMessages([
                'orden_servicio_id' => 'La orden seleccionada tiene movimientos financieros y no puede modificarse comercialmente.',
            ]);
        }

        if ($orden->cotizacion_id !== null && (int) $orden->cotizacion_id !== (int) $cotizacion->id) {
            throw ValidationException::withMessages([
                'orden_servicio_id' => 'La orden seleccionada ya tiene otra cotización vinculada.',
            ]);
        }
    }
}
