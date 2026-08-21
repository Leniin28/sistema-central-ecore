<?php

namespace App\Actions\Ordenes;

use App\Models\Equipo;
use App\Models\OrdenServicio;
use App\Models\Partner;
use App\Models\Servicio;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CrearOrdenServicio
{
    public function __construct(private CalcularTotalesOrdenServicio $calculadora) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $detalles
     * @param  array<int, array<string, mixed>>  $refacciones
     */
    public function ejecutar(array $data, array $detalles, array $refacciones, User $actor): OrdenServicio
    {
        return DB::transaction(function () use ($data, $detalles, $refacciones, $actor): OrdenServicio {
            $data = $this->prepararAsignaciones($data, $actor);
            if (! $actor->isAdmin()) {
                $data['costo_tecnico'] = null;
                $data['comision_recepcion'] = null;
            }
            $this->validarEquipo($data);
            $detalles = $this->normalizarDetalles($detalles);
            [$detalles, $refacciones] = $this->protegerCostosInternos($detalles, $refacciones, $actor);

            $orden = OrdenServicio::create([
                ...$data,
                'folio' => $this->generarFolio(),
                'estado' => 'recibido',
                'total_cliente' => 0,
                'costo_tecnico' => $data['costo_tecnico'] ?? null,
                'comision_logistica' => 0,
                'modelo_financiero' => OrdenServicio::MODELO_FINANCIERO_LEGACY,
                'utilidad_estimada' => 0,
                'costos_incompletos' => false,
                'utilidad_neta' => 0,
                'fecha_recepcion' => now(),
                'fecha_entrega' => null,
                'finanzas_generadas' => false,
                'creado_por_user_id' => $actor->id,
            ]);

            $orden->historialEstados()->create([
                'user_id' => $actor->id,
                'estado_anterior' => null,
                'estado_nuevo' => 'recibido',
                'comentario' => 'Orden creada',
            ]);

            foreach ($detalles as $detalle) {
                $orden->detalles()->create($this->calculadora->detalle($detalle));
            }

            foreach ($refacciones as $refaccion) {
                $orden->refacciones()->create($this->calculadora->refaccion($refaccion));
            }

            $resumen = $this->calculadora->resumen(
                $detalles,
                $refacciones,
                isset($data['costo_tecnico']) ? (float) $data['costo_tecnico'] : null,
                tienePartnerTecnico: ! empty($data['partner_tecnico_id']),
            );
            $orden->update([
                'total_cliente' => $resumen['total_cliente'],
                'utilidad_estimada' => $resumen['utilidad_estimada'],
                'costos_incompletos' => $resumen['costos_incompletos'],
            ]);

            return $orden->fresh();
        });
    }

    /** @param array<string, mixed> $data */
    private function prepararAsignaciones(array $data, User $actor): array
    {
        if (! $actor->isAdmin() && ! $actor->hasRole('socio_logistico')) {
            abort(403);
        }

        if ($actor->hasRole('socio_logistico')) {
            abort_if($actor->partner_id === null, 403);
            $this->validarPartner($actor->partner_id, 'logistico', 'partner_recepcion_id');
            $data['partner_recepcion_id'] = $actor->partner_id;
        } elseif ($actor->isAdmin() && ! empty($data['partner_recepcion_id'])) {
            $this->validarPartner((int) $data['partner_recepcion_id'], 'logistico', 'partner_recepcion_id');
        }

        if (! empty($data['partner_tecnico_id'])) {
            $this->validarPartner((int) $data['partner_tecnico_id'], 'tecnico', 'partner_tecnico_id');
        }

        return $data;
    }

    private function validarPartner(int $partnerId, string $tipo, string $campo): void
    {
        $valido = Partner::query()
            ->whereKey($partnerId)
            ->where('tipo_socio', $tipo)
            ->where('activo', true)
            ->exists();

        if (! $valido) {
            throw ValidationException::withMessages([
                $campo => 'El partner seleccionado no corresponde al tipo requerido o está inactivo.',
            ]);
        }
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

    /**
     * Internal costs may only be captured by an administrator. This guard
     * belongs in the action because web, reception, and future callers all
     * create orders through it.
     *
     * @param  array<int, array<string, mixed>>  $detalles
     * @param  array<int, array<string, mixed>>  $refacciones
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>}
     */
    private function protegerCostosInternos(array $detalles, array $refacciones, User $actor): array
    {
        if ($actor->isAdmin()) {
            return [$detalles, $refacciones];
        }

        return [
            array_map(function (array $detalle): array {
                $detalle['costo_unitario'] = null;

                return $detalle;
            }, $detalles),
            array_map(function (array $refaccion): array {
                $refaccion['costo_unitario'] = null;

                return $refaccion;
            }, $refacciones),
        ];
    }

    private function generarFolio(): string
    {
        $fecha = now()->format('Ymd');
        $ultimoFolio = OrdenServicio::query()
            ->where('folio', 'like', "OS-{$fecha}-%")
            ->lockForUpdate()
            ->orderByDesc('folio')
            ->value('folio');
        $consecutivo = $ultimoFolio ? ((int) substr($ultimoFolio, -4)) + 1 : 1;

        return 'OS-'.$fecha.'-'.str_pad((string) $consecutivo, 4, '0', STR_PAD_LEFT);
    }
}
