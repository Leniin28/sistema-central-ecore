<?php

namespace App\Actions\Ordenes;

use App\Actions\OpenClaw\ObtenerUsuarioSistema;
use App\Exceptions\ConfirmacionEntregaRequeridaException;
use App\Exceptions\OrdenBloqueadaException;
use App\Models\OpenClawOrderChange;
use App\Models\OrdenServicio;
use App\Services\GenerarFinanzasOrdenServicio;
use Illuminate\Support\Facades\DB;

/**
 * Changes a service order status from the internal API (OpenClaw), mirroring the
 * web panel's flow (OrdenServicioEstadoController): transaction + lock, closed-order
 * guard, status history entry, and — on `entregado` — the EXACT same
 * {@see GenerarFinanzasOrdenServicio} the panel uses (which is itself idempotent).
 *
 * Differences specific to the internal API:
 * - The actor is the OpenClaw system user (admin), so any transition is allowed,
 *   same as an admin in the panel.
 * - `entregado` additionally requires an explicit confirmation flag, because it
 *   closes the order and generates real financial movements.
 * - Idempotency by external_id via the openclaw_order_changes ledger (shared with
 *   the line-changes endpoint): a retried Telegram message never duplicates
 *   history or finances.
 */
class CambiarEstadoOrdenDesdeOpenClaw
{
    public function __construct(
        private ObtenerUsuarioSistema $usuarioSistema,
        private GenerarFinanzasOrdenServicio $finanzas,
    ) {}

    /**
     * @return array{orden: OrdenServicio, cambiado: bool, duplicado: bool, estado_anterior: string|null, warnings: array<int, string>}
     */
    public function ejecutar(
        OrdenServicio $orden,
        string $estadoNuevo,
        ?string $comentario = null,
        ?string $externalId = null,
        bool $confirmarEntrega = false,
    ): array {
        return DB::transaction(function () use ($orden, $estadoNuevo, $comentario, $externalId, $confirmarEntrega): array {
            $orden = OrdenServicio::query()->lockForUpdate()->findOrFail($orden->id);

            if (filled($externalId) && OpenClawOrderChange::query()->where('external_id', $externalId)->exists()) {
                return $this->resultado($orden, cambiado: false, duplicado: true, estadoAnterior: null);
            }

            if ($orden->finanzas_generadas || $orden->estado === 'entregado') {
                throw new OrdenBloqueadaException(
                    "La orden {$orden->folio} ya está entregada/cerrada (finanzas generadas); no admite más cambios de estado.",
                );
            }

            if ($estadoNuevo === $orden->estado) {
                return $this->resultado(
                    $orden,
                    cambiado: false,
                    duplicado: false,
                    estadoAnterior: $orden->estado,
                    warnings: ["La orden {$orden->folio} ya está en estado \"{$estadoNuevo}\"; no se aplicó ningún cambio."],
                );
            }

            if ($estadoNuevo === 'entregado' && ! $confirmarEntrega) {
                throw new ConfirmacionEntregaRequeridaException(
                    "Marcar la orden {$orden->folio} como entregada cierra la orden y genera finanzas. "
                    .'Reenvía la petición con confirm_final_delivery=true para confirmar.',
                );
            }

            $estadoAnterior = $orden->estado;

            // Mismo flujo que el panel: estado + fecha_entrega, historial y finanzas al entregar.
            $orden->update([
                'estado' => $estadoNuevo,
                'fecha_entrega' => $estadoNuevo === 'entregado' ? now() : $orden->fecha_entrega,
            ]);

            $orden->historialEstados()->create([
                'user_id' => $this->usuarioSistema->ejecutar()->id,
                'estado_anterior' => $estadoAnterior,
                'estado_nuevo' => $estadoNuevo,
                'comentario' => $comentario ?? 'Cambio de estado vía OpenClaw (Telegram)',
            ]);

            if ($estadoNuevo === 'entregado') {
                $this->finanzas->generar($orden);
            }

            if (filled($externalId)) {
                OpenClawOrderChange::create(['orden_servicio_id' => $orden->id, 'external_id' => $externalId]);
            }

            return $this->resultado($orden->fresh(), cambiado: true, duplicado: false, estadoAnterior: $estadoAnterior);
        });
    }

    /**
     * @param  array<int, string>  $warnings
     * @return array{orden: OrdenServicio, cambiado: bool, duplicado: bool, estado_anterior: string|null, warnings: array<int, string>}
     */
    private function resultado(
        OrdenServicio $orden,
        bool $cambiado,
        bool $duplicado,
        ?string $estadoAnterior,
        array $warnings = [],
    ): array {
        return [
            'orden' => $orden,
            'cambiado' => $cambiado,
            'duplicado' => $duplicado,
            'estado_anterior' => $estadoAnterior,
            'warnings' => $warnings,
        ];
    }
}
