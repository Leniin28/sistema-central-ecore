<?php

namespace App\Actions\Finanzas;

use App\Models\AjusteFinancieroOrden;
use App\Models\MovimientoFinanciero;
use App\Models\OrdenServicio;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Escenarios A/B/G: reembolso parcial o total de una orden ya entregada. La
 * orden permanece entregada, la venta original (total_cliente) nunca se toca
 * y el reembolso se registra como su propio evento con fecha real, sin
 * reescribir retroactivamente el periodo de la venta. venta_neta es siempre
 * derivada (total_cliente - suma de reembolsos), nunca almacenada.
 */
class RegistrarReembolsoOrdenServicio
{
    public function ejecutar(
        OrdenServicio $orden,
        float $monto,
        string $motivo,
        User $actor,
        ?CarbonImmutable $fecha = null,
    ): AjusteFinancieroOrden {
        abort_unless($actor->isAdmin(), 403);

        return DB::transaction(function () use ($orden, $monto, $motivo, $actor, $fecha): AjusteFinancieroOrden {
            $orden = OrdenServicio::query()->lockForUpdate()->findOrFail($orden->id);

            if ($orden->estado === 'cancelado') {
                throw ValidationException::withMessages([
                    'monto' => 'Esta orden fue cancelada; no se pueden registrar reembolsos sobre ella.',
                ]);
            }

            if ($orden->estado !== 'entregado' || ! $orden->finanzas_generadas) {
                throw ValidationException::withMessages([
                    'monto' => 'Solo se pueden registrar reembolsos sobre órdenes entregadas con finanzas generadas.',
                ]);
            }

            $montoRedondeado = round($monto, 2);
            if ($montoRedondeado <= 0) {
                throw ValidationException::withMessages([
                    'monto' => 'El monto del reembolso debe ser mayor a cero.',
                ]);
            }

            // Bloquear las filas existentes serializa reembolsos concurrentes
            // sobre la misma orden: el segundo request espera aquí y ve el
            // total ya actualizado del primero antes de validar el tope.
            $totalReembolsadoPrevio = round((float) AjusteFinancieroOrden::query()
                ->where('orden_servicio_id', $orden->id)
                ->where('tipo', AjusteFinancieroOrden::TIPO_REEMBOLSO_CLIENTE)
                ->lockForUpdate()
                ->sum('delta'), 2);

            $ventaBruta = (float) $orden->total_cliente;
            $totalReembolsadoNuevo = round($totalReembolsadoPrevio + $montoRedondeado, 2);

            if ($totalReembolsadoNuevo > $ventaBruta + 0.01) {
                $maximoAdicional = max(round($ventaBruta - $totalReembolsadoPrevio, 2), 0.0);

                throw ValidationException::withMessages([
                    'monto' => 'El reembolso excede el saldo disponible de esta orden. Total vendido: $'
                        .number_format($ventaBruta, 2).'. Total ya reembolsado: $'
                        .number_format($totalReembolsadoPrevio, 2).'. Máximo adicional disponible: $'
                        .number_format($maximoAdicional, 2).'.',
                ]);
            }

            $fechaReembolso = $fecha ?? CarbonImmutable::today();

            $ajuste = AjusteFinancieroOrden::create([
                'orden_servicio_id' => $orden->id,
                'tipo' => AjusteFinancieroOrden::TIPO_REEMBOLSO_CLIENTE,
                'motivo' => $motivo,
                'actor_user_id' => $actor->id,
                'fecha' => $fechaReembolso,
                'valor_anterior' => $totalReembolsadoPrevio,
                'valor_nuevo' => $totalReembolsadoNuevo,
                'delta' => $montoRedondeado,
            ]);

            MovimientoFinanciero::create([
                'orden_servicio_id' => $orden->id,
                'cotizacion_id' => $orden->cotizacion_id,
                'cliente_id' => $orden->cliente_id,
                'tipo' => 'egreso',
                'categoria' => AjusteFinancieroOrden::TIPO_REEMBOLSO_CLIENTE,
                'monto' => $montoRedondeado,
                'descripcion' => 'Reembolso a cliente de orden '.$orden->folio.' ('.$motivo.')',
                'fecha' => $fechaReembolso,
                'ajuste_financiero_orden_id' => $ajuste->id,
            ]);

            return $ajuste->fresh();
        });
    }
}
