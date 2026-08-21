<?php

namespace App\Actions\Finanzas;

use App\Actions\Ordenes\RecalcularTotalesOrdenServicio;
use App\Models\AjusteFinancieroOrden;
use App\Models\GeneracionFinancieraOrden;
use App\Models\HistorialEstado;
use App\Models\MovimientoFinanciero;
use App\Models\OrdenServicio;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Escenario F: anula una entrega marcada por error -- únicamente cuando esa
 * entrega tiene trazabilidad estructurada (FASE H.2: GeneracionFinancieraOrden).
 * Entregas históricas sin lote no pueden anularse aquí; no se infiere ni se
 * hace backfill (ver GATE de FASE H.6).
 *
 * Compensa TODOS los movimientos del lote (nunca los edita ni los elimina),
 * nunca toca anticipos preexistentes (creados antes de que el lote
 * existiera, por lo tanto nunca pertenecen a él), y restaura la orden al
 * estado inmediatamente anterior a "entregado" según HistorialEstado.
 */
class AnularEntregaOrdenServicio
{
    public function __construct(private RecalcularTotalesOrdenServicio $recalcularTotales) {}

    public function ejecutar(OrdenServicio $orden, string $motivo, User $actor): OrdenServicio
    {
        abort_unless($actor->isAdmin(), 403);

        return DB::transaction(function () use ($orden, $motivo, $actor): OrdenServicio {
            $orden = OrdenServicio::query()->lockForUpdate()->findOrFail($orden->id);

            if ($orden->estado !== 'entregado' || ! $orden->finanzas_generadas) {
                throw ValidationException::withMessages([
                    'orden' => 'Solo se puede anular la entrega de una orden entregada con finanzas generadas.',
                ]);
            }

            $lote = GeneracionFinancieraOrden::query()
                ->where('orden_servicio_id', $orden->id)
                ->where('tipo', GeneracionFinancieraOrden::TIPO_ENTREGA)
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if ($lote === null) {
                throw ValidationException::withMessages([
                    'orden' => 'Esta entrega es anterior a la trazabilidad necesaria y requiere revisión manual.',
                ]);
            }

            if ($lote->estaAnulada()) {
                throw ValidationException::withMessages([
                    'orden' => 'Esta entrega ya fue anulada.',
                ]);
            }

            $ajustesPosteriores = AjusteFinancieroOrden::query()
                ->where('orden_servicio_id', $orden->id)
                ->where('created_at', '>=', $lote->created_at)
                ->exists();

            if ($ajustesPosteriores) {
                throw ValidationException::withMessages([
                    'orden' => 'Existen reembolsos o correcciones registrados después de esta entrega; anular quedaría ambiguo. Revísalos antes de continuar.',
                ]);
            }

            $estadoAnteriorRegistro = HistorialEstado::query()
                ->where('orden_servicio_id', $orden->id)
                ->where('estado_nuevo', 'entregado')
                ->whereNotNull('estado_anterior')
                ->latest('id')
                ->first();

            if ($estadoAnteriorRegistro === null) {
                throw ValidationException::withMessages([
                    'orden' => 'No se puede determinar de forma inequívoca el estado anterior a la entrega. Requiere revisión manual.',
                ]);
            }

            $movimientosLote = MovimientoFinanciero::query()
                ->where('generacion_financiera_orden_id', $lote->id)
                ->lockForUpdate()
                ->get();

            if ($movimientosLote->isEmpty()) {
                throw ValidationException::withMessages([
                    'orden' => 'El lote de esta entrega no tiene movimientos asociados. Requiere revisión manual.',
                ]);
            }

            $ajuste = AjusteFinancieroOrden::create([
                'orden_servicio_id' => $orden->id,
                'tipo' => AjusteFinancieroOrden::TIPO_ANULACION_ENTREGA,
                'motivo' => $motivo,
                'actor_user_id' => $actor->id,
                'fecha' => today(),
                'generacion_financiera_orden_id' => $lote->id,
            ]);

            $movimientosCompensadosIds = [];
            foreach ($movimientosLote as $movimiento) {
                $compensatorio = MovimientoFinanciero::create([
                    'orden_servicio_id' => $orden->id,
                    'cotizacion_id' => $movimiento->cotizacion_id,
                    'generacion_financiera_orden_id' => $lote->id,
                    'cliente_id' => $movimiento->cliente_id,
                    'partner_id' => $movimiento->partner_id,
                    'tipo' => $movimiento->tipo === 'ingreso' ? 'egreso' : 'ingreso',
                    'categoria' => $movimiento->categoria,
                    'monto' => $movimiento->monto,
                    'descripcion' => 'Anulación de entrega: compensación de "'.$movimiento->descripcion.'" (mov #'.$movimiento->id.') — '.$motivo,
                    'fecha' => today(),
                    'ajuste_financiero_orden_id' => $ajuste->id,
                ]);
                $movimientosCompensadosIds[] = ['original_id' => $movimiento->id, 'compensatorio_id' => $compensatorio->id];
            }

            $ajuste->update([
                'metadata' => [
                    'movimientos_compensados' => $movimientosCompensadosIds,
                    'estado_restaurado' => $estadoAnteriorRegistro->estado_anterior,
                ],
            ]);

            $estadoRestaurado = $estadoAnteriorRegistro->estado_anterior;
            $comisionLogisticaRestaurada = $orden->usaModeloFinancieroLegacy() ? 0 : $orden->comision_logistica;

            // utilidad_neta es NOT NULL en el esquema (default 0): 0 es la
            // convención real del sistema antes del cierre -- no NULL.
            $orden->update([
                'estado' => $estadoRestaurado,
                'finanzas_generadas' => false,
                'fecha_entrega' => null,
                'utilidad_neta' => 0,
                'comision_logistica' => $comisionLogisticaRestaurada,
            ]);

            $this->recalcularTotales->ejecutar($orden);

            $orden->historialEstados()->create([
                'user_id' => $actor->id,
                'estado_anterior' => 'entregado',
                'estado_nuevo' => $estadoRestaurado,
                'comentario' => 'Entrega anulada por error: '.$motivo,
            ]);

            return $orden->fresh();
        });
    }
}
