<?php

namespace App\Actions\OpenClaw;

use App\Models\Partner;
use Illuminate\Support\Str;

/**
 * Resolves a logistics partner (branch) from free text detected by OpenClaw
 * (label photos, Telegram corrections, quote conversions). Case/accent
 * insensitive and tolerant to variants ("Alameda" → "Electrocom Alameda").
 * A miss or an ambiguous match never fails the operation: the caller gets a
 * warning instead of an exception, so the order can be fixed in the panel.
 */
class ResolverPartnerLogistico
{
    /**
     * @return array{id: int|null, warning: string|null, texto: string|null}
     */
    public function ejecutar(?string $texto): array
    {
        $texto = is_string($texto) ? trim($texto) : null;

        if ($texto === null || $texto === '') {
            return ['id' => null, 'warning' => null, 'texto' => null];
        }

        $match = $this->buscarPorNombre($texto);

        if ($match instanceof Partner) {
            return ['id' => $match->id, 'warning' => null, 'texto' => $texto];
        }

        $warning = $match === 'ambiguo'
            ? "La sucursal \"{$texto}\" coincide con más de un partner logístico; asígnalo manualmente en el panel."
            : "No se encontró un partner logístico que coincida con \"{$texto}\"; la orden quedó sin partner. Asígnalo en el panel.";

        return ['id' => null, 'warning' => $warning, 'texto' => $texto];
    }

    /**
     * @return Partner|string|null  Partner if unique match; 'ambiguo' if several; null if none.
     */
    private function buscarPorNombre(string $texto): Partner|string|null
    {
        $objetivo = $this->normalizar($texto);
        if ($objetivo === '') {
            return null;
        }

        $partners = Partner::query()
            ->where('tipo_socio', 'logistico')
            ->where('activo', true)
            ->get();

        // 1. Coincidencia exacta (normalizada).
        $exactos = $partners->filter(
            fn (Partner $partner): bool => $this->normalizar($partner->nombre) === $objetivo,
        )->values();
        if ($exactos->count() === 1) {
            return $exactos->first();
        }
        if ($exactos->count() > 1) {
            return 'ambiguo';
        }

        // 2. Coincidencia parcial en cualquier dirección ("Alameda" ⊂ "Electrocom Alameda").
        $parciales = $partners->filter(function (Partner $partner) use ($objetivo): bool {
            $nombre = $this->normalizar($partner->nombre);

            return $nombre !== '' && (str_contains($nombre, $objetivo) || str_contains($objetivo, $nombre));
        })->values();
        if ($parciales->count() === 1) {
            return $parciales->first();
        }
        if ($parciales->count() > 1) {
            return 'ambiguo';
        }

        return null;
    }

    private function normalizar(string $texto): string
    {
        return Str::of($texto)->ascii()->lower()->squish()->value();
    }
}
