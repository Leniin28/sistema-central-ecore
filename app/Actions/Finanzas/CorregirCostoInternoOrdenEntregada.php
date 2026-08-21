<?php

namespace App\Actions\Finanzas;

use App\Actions\Cotizaciones\CalcularTotalesCotizacion;
use App\Actions\Ordenes\CalcularTotalesOrdenServicio;
use App\Actions\Ordenes\PoliticaCostosOrdenServicio;
use App\Models\AjusteFinancieroOrden;
use App\Models\CotizacionItem;
use App\Models\MovimientoFinanciero;
use App\Models\OrdenServicio;
use App\Models\OrdenServicioDetalle;
use App\Models\OrdenServicioRefaccion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Escenarios C/E1: corrige el costo interno de una línea (servicio o
 * refacción) de una orden YA entregada. El movimiento original que generó
 * GenerarFinanzasOrdenServicio nunca se edita ni se elimina; el delta entre
 * el costo anterior y el nuevo se compensa con un movimiento nuevo (egreso si
 * el costo subió, ingreso si bajó) para que el costo neto termine correcto.
 * Nunca toca precio_cliente, cantidad ni descripción vendida.
 */
class CorregirCostoInternoOrdenEntregada
{
    public function __construct(
        private CalcularTotalesOrdenServicio $calculadoraOrden,
        private CalcularTotalesCotizacion $calculadoraCotizacion,
    ) {}

    public function ejecutar(
        OrdenServicio $orden,
        string $lineaTipo,
        int $lineaId,
        float $costoNuevo,
        string $motivo,
        User $actor,
    ): OrdenServicio {
        abort_unless($actor->isAdmin(), 403);

        if (! in_array($lineaTipo, ['servicio', 'refaccion'], true)) {
            throw ValidationException::withMessages([
                'linea_tipo' => 'Tipo de línea no válido.',
            ]);
        }

        if ($costoNuevo < 0) {
            throw ValidationException::withMessages([
                'costo_nuevo' => 'El costo corregido no puede ser negativo.',
            ]);
        }

        return DB::transaction(function () use ($orden, $lineaTipo, $lineaId, $costoNuevo, $motivo, $actor): OrdenServicio {
            $orden = OrdenServicio::query()->lockForUpdate()->findOrFail($orden->id);
            $this->asegurarEntregadaConFinanzas($orden);

            $linea = $lineaTipo === 'servicio'
                ? OrdenServicioDetalle::query()->where('orden_servicio_id', $orden->id)->where('id', $lineaId)->lockForUpdate()->first()
                : OrdenServicioRefaccion::query()->where('orden_servicio_id', $orden->id)->where('id', $lineaId)->lockForUpdate()->first();

            if ($linea === null) {
                throw ValidationException::withMessages([
                    'linea_id' => 'La línea indicada no pertenece a esta orden.',
                ]);
            }

            $costoUnitarioAnterior = $linea->costo_unitario === null ? null : (float) $linea->costo_unitario;
            $costoTotalAnterior = $linea->costo_total === null ? 0.0 : (float) $linea->costo_total;

            $calculada = $lineaTipo === 'servicio'
                ? $this->calculadoraOrden->detalle([
                    'servicio_id' => $linea->servicio_id,
                    'descripcion' => $linea->descripcion,
                    'cantidad' => $linea->cantidad,
                    'precio_unitario' => $linea->precio_unitario,
                    'costo_unitario' => $costoNuevo,
                    'notas' => $linea->notas,
                ])
                : $this->calculadoraOrden->refaccion([
                    'descripcion' => $linea->descripcion,
                    'cantidad' => $linea->cantidad,
                    'precio_unitario_cliente' => $linea->precio_unitario_cliente,
                    'costo_unitario' => $costoNuevo,
                    'notas' => $linea->notas,
                ]);

            $costoTotalNuevo = (float) $calculada['costo_total'];

            if (round($costoTotalNuevo - $costoTotalAnterior, 2) === 0.0) {
                throw ValidationException::withMessages([
                    'costo_nuevo' => 'El costo corregido es igual al costo actual; no hay ningún ajuste que registrar.',
                ]);
            }

            if ($lineaTipo === 'servicio') {
                $linea->update([
                    'costo_unitario' => $calculada['costo_unitario'],
                    'costo_total' => $calculada['costo_total'],
                ]);
            } else {
                $linea->update([
                    'costo_unitario' => $calculada['costo_unitario'],
                    'costo_total' => $calculada['costo_total'],
                    'utilidad_refaccion' => $calculada['utilidad_refaccion'],
                ]);
            }

            $this->sincronizarCotizacionItem($linea->cotizacion_item_id, $orden->cotizacion_id, $lineaTipo, $calculada['costo_unitario']);

            $delta = round($costoTotalNuevo - $costoTotalAnterior, 2);
            $tipoMovimiento = $delta > 0 ? 'egreso' : 'ingreso';
            $categoria = $lineaTipo === 'servicio' ? 'servicio' : 'refaccion';

            $ajuste = AjusteFinancieroOrden::create([
                'orden_servicio_id' => $orden->id,
                'tipo' => $lineaTipo === 'servicio'
                    ? AjusteFinancieroOrden::TIPO_CORRECCION_COSTO_SERVICIO
                    : AjusteFinancieroOrden::TIPO_CORRECCION_COSTO_REFACCION,
                'motivo' => $motivo,
                'actor_user_id' => $actor->id,
                'fecha' => today(),
                'linea_tipo' => $lineaTipo,
                'linea_id' => $linea->id,
                'valor_anterior' => $costoUnitarioAnterior,
                'valor_nuevo' => $calculada['costo_unitario'],
                'delta' => $delta,
                'metadata' => [
                    'cantidad' => $linea->cantidad,
                    'costo_total_anterior' => $costoTotalAnterior,
                    'costo_total_nuevo' => $costoTotalNuevo,
                    'descripcion_linea' => $linea->descripcion,
                ],
            ]);

            MovimientoFinanciero::create([
                'orden_servicio_id' => $orden->id,
                'cotizacion_id' => $orden->cotizacion_id,
                'cliente_id' => $orden->cliente_id,
                'tipo' => $tipoMovimiento,
                'categoria' => $categoria,
                'monto' => abs($delta),
                'descripcion' => 'Corrección de costo interno de '.($lineaTipo === 'servicio' ? 'servicio' : 'refacción')
                    .' "'.$linea->descripcion.'" en orden '.$orden->folio.' ('.$motivo.')',
                'fecha' => today(),
                'ajuste_financiero_orden_id' => $ajuste->id,
            ]);

            $this->actualizarUtilidad($orden);

            return $orden->fresh(['detalles', 'refacciones']);
        });
    }

    private function asegurarEntregadaConFinanzas(OrdenServicio $orden): void
    {
        if ($orden->estado !== 'entregado' || ! $orden->finanzas_generadas) {
            throw ValidationException::withMessages([
                'orden' => 'Solo se pueden corregir costos internos de órdenes entregadas con finanzas generadas.',
            ]);
        }
    }

    private function sincronizarCotizacionItem(?int $itemId, ?int $cotizacionId, string $lineaTipo, ?float $costoUnitarioNuevo): void
    {
        if ($itemId === null) {
            return;
        }

        $item = CotizacionItem::query()->lockForUpdate()->find($itemId);

        if ($item === null || (int) $item->cotizacion_id !== (int) $cotizacionId) {
            throw ValidationException::withMessages([
                'linea_id' => 'La trazabilidad entre la cotización y la línea de la orden no es válida.',
            ]);
        }

        $tipoEsperado = $lineaTipo === 'servicio' ? 'servicio' : 'refaccion';
        if ($item->tipo !== $tipoEsperado) {
            throw ValidationException::withMessages([
                'linea_id' => 'La trazabilidad entre la cotización y la línea de la orden no es válida.',
            ]);
        }

        $calculado = $this->calculadoraCotizacion->item([
            'tipo' => $item->tipo,
            'servicio_id' => $item->servicio_id,
            'descripcion' => $item->descripcion,
            'cantidad' => $item->cantidad,
            'precio_unitario' => $item->precio_unitario,
            'costo_unitario' => $costoUnitarioNuevo,
        ]);

        $item->update([
            'costo_unitario' => $calculado['costo_unitario'],
            'costo_total' => $calculado['costo_total'],
        ]);
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
