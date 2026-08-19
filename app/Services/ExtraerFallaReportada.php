<?php

namespace App\Services;

class ExtraerFallaReportada
{
    public function extraer(?string $notas): ?string
    {
        if (blank($notas)) {
            return null;
        }

        if (preg_match_all('/^Falla reportada \(corregida\):[ \t]*(.+)$/miu', $notas, $corregidas) && $corregidas[1] !== []) {
            return trim((string) end($corregidas[1]));
        }

        foreach (['Problema reportado', 'Falla reportada'] as $etiqueta) {
            $patron = '/^'.preg_quote($etiqueta, '/').':[ \t]*(?:\R)?(.+?)(?=\R{2,}|\z)/msu';

            if (preg_match($patron, $notas, $coincidencia)) {
                $falla = trim($coincidencia[1]);

                return $falla === '(no especificada en la etiqueta)' ? null : $falla;
            }
        }

        return null;
    }

    public function extraerObservaciones(?string $notas): ?string
    {
        if (blank($notas)) {
            return null;
        }

        if (! preg_match('/^Problema reportado:.*?\R{2,}Notas internas:[ \t]*(?:\R)?(.+)\z/msu', $notas, $coincidencia)) {
            return null;
        }

        return trim($coincidencia[1]) ?: null;
    }
}
