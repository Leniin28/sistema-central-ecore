<?php

namespace App\Http\Controllers;

use App\Models\OrdenServicio;
use App\Services\GenerarFinanzasOrdenServicio;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class OrdenServicioEstadoController extends Controller
{
    // Lista canónica compartida con la API interna (OpenClaw).
    private const ESTADOS = OrdenServicio::ESTADOS;

    /**
     * Store a service order status change.
     */
    public function store(Request $request, OrdenServicio $ordenServicio): RedirectResponse
    {
        $data = $request->validate([
            'estado_nuevo' => ['required', Rule::in(self::ESTADOS)],
            'comentario' => ['nullable', 'string'],
        ]);

        DB::transaction(function () use ($ordenServicio, $data): void {
            $ordenServicio = OrdenServicio::query()->lockForUpdate()->findOrFail($ordenServicio->id);
            $this->authorizeChange($ordenServicio);
            abort_if($ordenServicio->estado === 'entregado' || $ordenServicio->finanzas_generadas, 403);
            abort_if($data['estado_nuevo'] === $ordenServicio->estado, 403);
            abort_unless(in_array($data['estado_nuevo'], $this->estadosDisponibles($ordenServicio), true), 403);

            $estadoAnterior = $ordenServicio->estado;

            $ordenServicio->update([
                'estado' => $data['estado_nuevo'],
                'fecha_entrega' => $data['estado_nuevo'] === 'entregado'
                    ? now()
                    : $ordenServicio->fecha_entrega,
            ]);

            $ordenServicio->historialEstados()->create([
                'user_id' => auth()->id(),
                'estado_anterior' => $estadoAnterior,
                'estado_nuevo' => $data['estado_nuevo'],
                'comentario' => $data['comentario'] ?? null,
            ]);

            if ($data['estado_nuevo'] === 'entregado') {
                app(GenerarFinanzasOrdenServicio::class)->generar($ordenServicio);
            }
        });

        return redirect()
            ->route($this->routePrefix().'.ordenes-servicio.show', $ordenServicio)
            ->with('status', 'Estado actualizado correctamente.');
    }

    /**
     * Authorize the current user to change this order status.
     */
    private function authorizeChange(OrdenServicio $orden): void
    {
        $user = auth()->user();

        if ($user->isAdmin()) {
            return;
        }

        if ($user->hasRole('socio_logistico') && $orden->partner_recepcion_id === $user->partner_id) {
            return;
        }

        if ($user->hasRole('socio_tecnico') && $orden->partner_tecnico_id === $user->partner_id) {
            return;
        }

        abort(403);
    }

    /**
     * Get available statuses for the current user and order state.
     *
     * @return array<int, string>
     */
    private function estadosDisponibles(OrdenServicio $orden): array
    {
        if ($orden->estado === 'entregado' || $orden->finanzas_generadas) {
            return [];
        }

        $user = auth()->user();

        if ($user->isAdmin()) {
            return array_values(array_filter(
                self::ESTADOS,
                fn (string $estado): bool => $estado !== $orden->estado,
            ));
        }

        if ($user->hasRole('socio_logistico')) {
            return $orden->estado === 'listo_para_entregar'
                ? ['entregado']
                : [];
        }

        if ($user->hasRole('socio_tecnico')) {
            return match ($orden->estado) {
                'recibido' => ['en_diagnostico'],
                'en_diagnostico' => ['cotizacion_pendiente'],
                'cotizacion_aprobada' => ['en_proceso'],
                'en_proceso' => ['listo_para_entregar'],
                'en_fixop' => ['listo_para_entregar'],
                default => [],
            };
        }

        return [];
    }

    /**
     * Get the route prefix for the authenticated user's role.
     */
    private function routePrefix(): string
    {
        return match (auth()->user()->role) {
            'admin' => 'admin',
            'socio_logistico' => 'logistica',
            'socio_tecnico' => 'tecnico',
            default => abort(403),
        };
    }
}
