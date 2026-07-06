<?php

namespace App\Actions\Servicios;

use App\Models\Servicio;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Single source of truth for matching free text against the ACTIVE service
 * catalog. Two levels:
 *
 * - matchUnico(): the strict rule the order/reception/conversion flows bill
 *   with (exact normalized match, then bidirectional partial); ambiguity or a
 *   miss never picks a service. Extracted verbatim from
 *   AplicarCambiosOrdenDesdeOpenClaw so /changes behavior did not change.
 * - ejecutar(): advisory scoring for the /services/match endpoint — adds a
 *   token-overlap fallback so long phrases ("optimización completa limpieza
 *   pasta térmica") still surface candidates, with an explicit confidence so
 *   OpenClaw never auto-picks on ambiguity.
 *
 * It never creates catalog entries.
 */
class MatchearServicioCatalogo
{
    /** Words ignored when tokenizing service names. */
    private const STOPWORDS = ['de', 'del', 'la', 'el', 'los', 'las', 'y', 'o', 'con', 'para', 'por', 'servicio'];

    /**
     * Strict match used to create real billable lines.
     *
     * @return Servicio|string|null  Servicio si hay match único; 'ambiguo' si varios; null si ninguno.
     */
    public function matchUnico(string $texto): Servicio|string|null
    {
        $objetivo = $this->normalizar($texto);
        if ($objetivo === '') {
            return null;
        }

        $servicios = $this->activos();

        $exactos = $servicios->filter(
            fn (Servicio $servicio): bool => $this->normalizar($servicio->nombre) === $objetivo,
        )->values();
        if ($exactos->count() === 1) {
            return $exactos->first();
        }
        if ($exactos->count() > 1) {
            return 'ambiguo';
        }

        $parciales = $servicios->filter(function (Servicio $servicio) use ($objetivo): bool {
            $nombre = $this->normalizar($servicio->nombre);

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

    /**
     * Advisory match with candidates and confidence for OpenClaw.
     *
     * @return array{match: Servicio|null, confidence: 'high'|'ambiguous'|'none', candidates: Collection<int, array{servicio: Servicio, score: float}>}
     */
    public function ejecutar(string $texto, int $limite = 5): array
    {
        $candidatos = $this->puntuar($texto)->take($limite)->values();

        // La regla estricta manda: si ella elige, la confianza es alta.
        $estricto = $this->matchUnico($texto);
        if ($estricto instanceof Servicio) {
            return ['match' => $estricto, 'confidence' => 'high', 'candidates' => $candidatos];
        }
        if ($estricto === 'ambiguo') {
            return ['match' => null, 'confidence' => 'ambiguous', 'candidates' => $candidatos];
        }

        // Fallback por tokens para frases largas.
        if ($candidatos->isEmpty()) {
            return ['match' => null, 'confidence' => 'none', 'candidates' => $candidatos];
        }

        $primero = $candidatos->first();
        $segundo = $candidatos->get(1);
        $esClaro = $primero['score'] >= 0.99
            && ($segundo === null || $primero['score'] - $segundo['score'] >= 0.25);

        if ($esClaro) {
            return ['match' => $primero['servicio'], 'confidence' => 'high', 'candidates' => $candidatos];
        }

        return [
            'match' => null,
            'confidence' => $primero['score'] >= 0.5 ? 'ambiguous' : 'none',
            'candidates' => $candidatos,
        ];
    }

    /**
     * Score every active service against the text by token overlap of its name
     * (share of the service-name tokens present in the text).
     *
     * @return Collection<int, array{servicio: Servicio, score: float}>
     */
    private function puntuar(string $texto): Collection
    {
        $tokensTexto = $this->tokens($texto);
        if ($tokensTexto === []) {
            return collect();
        }

        return $this->activos()
            ->map(function (Servicio $servicio) use ($tokensTexto): ?array {
                $tokensNombre = $this->tokens($servicio->nombre);
                if ($tokensNombre === []) {
                    return null;
                }

                $coincidencias = count(array_filter(
                    $tokensNombre,
                    fn (string $token): bool => in_array($token, $tokensTexto, true),
                ));

                return $coincidencias === 0 ? null : [
                    'servicio' => $servicio,
                    'score' => round($coincidencias / count($tokensNombre), 2),
                ];
            })
            ->filter()
            ->sortByDesc('score')
            ->values();
    }

    /**
     * @return array<int, string>
     */
    private function tokens(string $texto): array
    {
        $palabras = explode(' ', $this->normalizar($texto));

        return array_values(array_filter(
            $palabras,
            fn (string $palabra): bool => mb_strlen($palabra) >= 3 && ! in_array($palabra, self::STOPWORDS, true),
        ));
    }

    /**
     * @return Collection<int, Servicio>
     */
    private function activos(): Collection
    {
        return Servicio::query()->where('activo', true)->with('categoriaServicio')->get();
    }

    public function normalizar(string $texto): string
    {
        return Str::of($texto)->ascii()->lower()->squish()->value();
    }
}
