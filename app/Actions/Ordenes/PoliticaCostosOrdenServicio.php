<?php

namespace App\Actions\Ordenes;

use App\Models\OrdenServicio;

/**
 * Single source of truth for which costs apply under each modelo_financiero.
 * Used by both CalcularTotalesOrdenServicio (create path) and
 * RecalcularTotalesOrdenServicio (update path) so they never diverge.
 */
class PoliticaCostosOrdenServicio
{
    public static function esLegacy(string $modeloFinanciero): bool
    {
        return $modeloFinanciero === OrdenServicio::MODELO_FINANCIERO_LEGACY;
    }

    /** Comisión que resta a la utilidad: comision_logistica en legacy, comision_recepcion en costos_por_linea. */
    public static function comisionAplicable(
        string $modeloFinanciero,
        float $comisionLogistica,
        ?float $comisionRecepcion,
    ): float {
        return self::esLegacy($modeloFinanciero) ? $comisionLogistica : ($comisionRecepcion ?? 0.0);
    }

    /** costo_tecnico sólo participa en legacy; en costos_por_linea se ignora por completo. */
    public static function costoTecnicoAplicable(string $modeloFinanciero, ?float $costoTecnico): float
    {
        return self::esLegacy($modeloFinanciero) ? ($costoTecnico ?? 0.0) : 0.0;
    }

    /**
     * comision_recepcion sólo es un requisito real cuando el equipo fue
     * dejado/recibido en sucursal: ahí NULL significa "pendiente de
     * confirmar" y bloquea la entrega. En domicilio (o recepción "directa",
     * sin trámite físico de sucursal) no hay comisión de sucursal que
     * confirmar, así que NULL no es un dato faltante -- comisionAplicable()
     * ya trata NULL como 0 sin necesitar un snapshot artificial. Legacy no
     * usa comision_recepcion en absoluto (usa comision_logistica), así que
     * nunca la requiere.
     */
    public static function requiereComisionRecepcion(string $modeloFinanciero, string $tipoRecepcion): bool
    {
        return ! self::esLegacy($modeloFinanciero) && $tipoRecepcion === 'sucursal';
    }

    public static function costosIncompletos(
        string $modeloFinanciero,
        string $tipoRecepcion,
        bool $tienePartnerTecnico,
        ?float $costoTecnico,
        ?float $comisionRecepcion,
        bool $hayServicioSinCosto,
        bool $hayRefaccionSinCosto,
    ): bool {
        if (self::esLegacy($modeloFinanciero)) {
            return ($tienePartnerTecnico && $costoTecnico === null) || $hayServicioSinCosto || $hayRefaccionSinCosto;
        }

        $comisionPendiente = self::requiereComisionRecepcion($modeloFinanciero, $tipoRecepcion) && $comisionRecepcion === null;

        return $comisionPendiente || $hayServicioSinCosto || $hayRefaccionSinCosto;
    }

    /**
     * Single formula for order profit: total_cliente minus internal line costs
     * minus whichever commission/technical-cost apply under the model. Used by
     * the quote/edit path (Calcular/Recalcular) and by finance generation, so
     * the pre-delivery estimate and the post-delivery close never diverge.
     */
    public static function utilidad(
        string $modeloFinanciero,
        float $totalCliente,
        float $costoServicios,
        float $costoRefacciones,
        float $comisionLogistica,
        ?float $comisionRecepcion,
        ?float $costoTecnico,
    ): float {
        $comisionAplicable = self::comisionAplicable($modeloFinanciero, $comisionLogistica, $comisionRecepcion);
        $costoTecnicoAplicable = self::costoTecnicoAplicable($modeloFinanciero, $costoTecnico);

        return round($totalCliente - $costoServicios - $costoRefacciones - $costoTecnicoAplicable - $comisionAplicable, 2);
    }
}
