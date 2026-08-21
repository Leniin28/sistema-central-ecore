<?php

namespace App\Actions\Ordenes;

use App\Models\OrdenServicio;
use RuntimeException;

/**
 * Single server-side source of truth for which modelo_financiero a brand-new
 * order gets. No channel (web, API, OpenClaw, cotizaciones) may choose it via
 * request data; only this config-backed resolver decides. Fails closed on an
 * invalid config value instead of silently defaulting to legacy. Never used
 * to change the model of an order that already exists — that stays frozen
 * per row.
 */
class ResolverModeloFinancieroNuevaOrden
{
    public function ejecutar(): string
    {
        $modelo = config('negocio.modelo_financiero_nuevas_ordenes');

        if (! in_array($modelo, [
            OrdenServicio::MODELO_FINANCIERO_LEGACY,
            OrdenServicio::MODELO_FINANCIERO_COSTOS_POR_LINEA,
        ], true)) {
            throw new RuntimeException(
                "Configuración inválida: negocio.modelo_financiero_nuevas_ordenes = '".var_export($modelo, true)."'.",
            );
        }

        return $modelo;
    }
}
