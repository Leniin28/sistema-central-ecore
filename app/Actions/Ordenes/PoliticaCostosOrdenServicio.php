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

    public static function costosIncompletos(
        string $modeloFinanciero,
        bool $tienePartnerTecnico,
        ?float $costoTecnico,
        ?float $comisionRecepcion,
        bool $hayServicioSinCosto,
        bool $hayRefaccionSinCosto,
    ): bool {
        if (self::esLegacy($modeloFinanciero)) {
            return ($tienePartnerTecnico && $costoTecnico === null) || $hayServicioSinCosto || $hayRefaccionSinCosto;
        }

        return $comisionRecepcion === null || $hayServicioSinCosto || $hayRefaccionSinCosto;
    }
}
