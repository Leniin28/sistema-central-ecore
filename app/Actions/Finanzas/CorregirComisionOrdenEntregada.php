<?php

namespace App\Actions\Finanzas;

use App\Actions\Ordenes\PoliticaCostosOrdenServicio;
use App\Models\AjusteFinancieroOrden;
use App\Models\MovimientoFinanciero;
use App\Models\OrdenServicio;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Escenario D: corrige la comisión de recepción/logística de una orden YA
 * entregada. costos_por_linea corrige comision_recepcion; legacy corrige
 * comision_logistica -- nunca se mezclan. El admin establece el importe real
 * explícitamente: esta corrección nunca lee Partner.comision_fija. El
 * movimiento original (pago_socio_logistico/comision_recepcion) nunca se
 * edita; el delta se compensa con un movimiento nuevo.
 */
class CorregirComisionOrdenEntregada
{
    public function ejecutar(
        OrdenServicio $orden,
        float $comisionNueva,
        string $motivo,
        User $actor,
    ): OrdenServicio {
        abort_unless($actor->isAdmin(), 403);

        if ($comisionNueva < 0) {
            throw ValidationException::withMessages([
                'comision_nueva' => 'La comisión corregida no puede ser negativa.',
            ]);
        }

        return DB::transaction(function () use ($orden, $comisionNueva, $motivo, $actor): OrdenServicio {
            $orden = OrdenServicio::query()->lockForUpdate()->findOrFail($orden->id);

            if ($orden->estado !== 'entregado' || ! $orden->finanzas_generadas) {
                throw ValidationException::withMessages([
                    'orden' => 'Solo se pueden corregir comisiones de órdenes entregadas con finanzas generadas.',
                ]);
            }

            $esCostosPorLinea = $orden->usaCostosPorLinea();
            $campo = $esCostosPorLinea ? 'comision_recepcion' : 'comision_logistica';
            $categoria = $esCostosPorLinea ? 'comision_recepcion' : 'pago_socio_logistico';
            $tipoAjuste = $esCostosPorLinea
                ? AjusteFinancieroOrden::TIPO_CORRECCION_COMISION_RECEPCION
                : AjusteFinancieroOrden::TIPO_CORRECCION_COMISION_LOGISTICA;

            $valorAnterior = (float) ($orden->{$campo} ?? 0);
            $comisionNuevaRedondeada = round($comisionNueva, 2);
            $delta = round($comisionNuevaRedondeada - $valorAnterior, 2);

            if ($delta === 0.0) {
                throw ValidationException::withMessages([
                    'comision_nueva' => 'La comisión corregida es igual a la actual; no hay ningún ajuste que registrar.',
                ]);
            }

            $orden->update([$campo => $comisionNuevaRedondeada]);

            $ajuste = AjusteFinancieroOrden::create([
                'orden_servicio_id' => $orden->id,
                'tipo' => $tipoAjuste,
                'motivo' => $motivo,
                'actor_user_id' => $actor->id,
                'fecha' => today(),
                'valor_anterior' => $valorAnterior,
                'valor_nuevo' => $comisionNuevaRedondeada,
                'delta' => $delta,
            ]);

            MovimientoFinanciero::create([
                'orden_servicio_id' => $orden->id,
                'cotizacion_id' => $orden->cotizacion_id,
                'cliente_id' => $orden->cliente_id,
                'partner_id' => $orden->partner_recepcion_id,
                'tipo' => $delta > 0 ? 'egreso' : 'ingreso',
                'categoria' => $categoria,
                'monto' => abs($delta),
                'descripcion' => 'Corrección de comisión de '.($esCostosPorLinea ? 'recepción' : 'logística').' en orden '.$orden->folio.' ('.$motivo.')',
                'fecha' => today(),
                'ajuste_financiero_orden_id' => $ajuste->id,
            ]);

            $this->actualizarUtilidad($orden);

            return $orden->fresh();
        });
    }

    private function actualizarUtilidad(OrdenServicio $orden): void
    {
        $orden->load(['detalles', 'refacciones']);

        $costoServicios = (float) $orden->detalles->sum('costo_total');
        $costoRefacciones = (float) $orden->refacciones->sum('costo_total');
        $costoTecnico = $orden->costo_tecnico === null ? null : (float) $orden->costo_tecnico;
        $comisionRecepcion = $orden->comision_recepcion === null ? null : (float) $orden->comision_recepcion;

        $utilidad = PoliticaCostosOrdenServicio::utilidad(
            $orden->modelo_financiero,
            (float) $orden->total_cliente,
            $costoServicios,
            $costoRefacciones,
            (float) $orden->comision_logistica,
            $comisionRecepcion,
            $costoTecnico,
        );

        $orden->update([
            'utilidad_estimada' => $utilidad,
            'utilidad_neta' => $utilidad,
            'costos_incompletos' => PoliticaCostosOrdenServicio::costosIncompletos(
                $orden->modelo_financiero,
                $orden->tipo_recepcion,
                $orden->partner_tecnico_id !== null,
                $costoTecnico,
                $comisionRecepcion,
                $orden->detalles->contains(fn ($detalle): bool => $detalle->costo_total === null),
                $orden->refacciones->contains(fn ($refaccion): bool => $refaccion->costo_total === null),
            ),
        ]);
    }
}
