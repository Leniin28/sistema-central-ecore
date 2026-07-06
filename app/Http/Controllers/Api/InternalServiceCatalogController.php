<?php

namespace App\Http\Controllers\Api;

use App\Actions\Servicios\MatchearServicioCatalogo;
use App\Http\Controllers\Controller;
use App\Models\Servicio;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Read-only service catalog for OpenClaw, so it never hardcodes services: the
 * panel creates/edits/deactivates them and OpenClaw queries the current state
 * before deciding what to add to an order or quote. Nothing here creates or
 * mutates catalog entries.
 */
class InternalServiceCatalogController extends Controller
{
    private const LIMIT_DEFAULT = 50;

    private const LIMIT_MAX = 100;

    public function __construct(private MatchearServicioCatalogo $matcher) {}

    /**
     * List catalog services (active by default), with a normalized,
     * accent-insensitive text filter.
     */
    public function index(Request $request): JsonResponse
    {
        $filtros = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'active' => ['nullable', 'in:true,false,1,0'],
            'category' => ['nullable', 'string', 'max:255'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.self::LIMIT_MAX],
        ]);

        $limite = (int) ($filtros['limit'] ?? self::LIMIT_DEFAULT);
        $activo = ! in_array($filtros['active'] ?? 'true', ['false', '0'], true);
        $warnings = [];

        $servicios = Servicio::query()
            ->where('activo', $activo)
            ->with('categoriaServicio')
            ->orderBy('nombre')
            ->get();

        // Filtros normalizados en PHP: tolerantes a acentos y mayúsculas
        // sin depender de la collation de la base.
        if (filled($filtros['category'] ?? null)) {
            $categoria = $this->matcher->normalizar($filtros['category']);
            $servicios = $servicios->filter(
                fn (Servicio $servicio): bool => str_contains(
                    $this->matcher->normalizar((string) $servicio->categoriaServicio?->nombre),
                    $categoria,
                ),
            )->values();
        }

        if (filled($filtros['q'] ?? null)) {
            $q = $this->matcher->normalizar($filtros['q']);
            $servicios = $servicios->filter(function (Servicio $servicio) use ($q): bool {
                $blanco = $this->matcher->normalizar(implode(' ', [
                    $servicio->nombre,
                    (string) $servicio->descripcion,
                    (string) $servicio->categoriaServicio?->nombre,
                ]));

                return str_contains($blanco, $q);
            })->values();

            if ($servicios->isEmpty()) {
                $warnings[] = "Ningún servicio ".($activo ? 'activo' : 'inactivo')." coincide con \"{$filtros['q']}\"; no elijas un servicio a ciegas, pide aclaración o usa POST /api/internal/services/match.";
            }
        }

        if ($servicios->count() > $limite) {
            $warnings[] = "Hay {$servicios->count()} servicios que coinciden; se devolvieron los primeros {$limite}. Acota con q o category.";
        }

        return response()->json([
            'items' => $servicios->take($limite)->map(fn (Servicio $servicio): array => [
                'id' => $servicio->id,
                'nombre' => $servicio->nombre,
                'descripcion' => $servicio->descripcion,
                'categoria' => $servicio->categoriaServicio?->nombre,
                'precio_base' => (float) $servicio->precio_base,
                'activo' => (bool) $servicio->activo,
                'aliases' => $this->aliases($servicio),
            ])->values()->all(),
            'total' => $servicios->count(),
            'warnings' => $warnings,
        ]);
    }

    /**
     * Advisory match of free text against the active catalog. `high` means
     * OpenClaw can send that servicio_id directly; `ambiguous`/`none` mean it
     * must ask the user instead of guessing. Never creates services.
     */
    public function match(Request $request): JsonResponse
    {
        $data = $request->validate([
            'text' => ['required', 'string', 'max:500'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:10'],
        ]);

        $resultado = $this->matcher->ejecutar($data['text'], (int) ($data['limit'] ?? 5));

        $warnings = match ($resultado['confidence']) {
            'ambiguous' => ['Hay varios servicios parecidos; pide al usuario que elija uno de los candidatos, no lo decidas automáticamente.'],
            'none' => ["Ningún servicio activo del catálogo coincide con \"{$data['text']}\"; el servicio no existe o está desactivado. No inventes: usa servicios_sugeridos o pide aclaración."],
            default => [],
        };

        return response()->json([
            'match' => $resultado['match'] ? [
                'id' => $resultado['match']->id,
                'nombre' => $resultado['match']->nombre,
                'categoria' => $resultado['match']->categoriaServicio?->nombre,
                'precio_base' => (float) $resultado['match']->precio_base,
                'confidence' => $resultado['confidence'],
            ] : null,
            'confidence' => $resultado['confidence'],
            'candidates' => $resultado['candidates']->map(fn (array $candidato): array => [
                'id' => $candidato['servicio']->id,
                'nombre' => $candidato['servicio']->nombre,
                'categoria' => $candidato['servicio']->categoriaServicio?->nombre,
                'precio_base' => (float) $candidato['servicio']->precio_base,
                'score' => $candidato['score'],
            ])->values()->all(),
            'warnings' => $warnings,
        ]);
    }

    /**
     * Derived aliases (there is no aliases column): safe, deterministic
     * variants of the name/category so OpenClaw can match colloquial wording.
     *
     * @return array<int, string>
     */
    private function aliases(Servicio $servicio): array
    {
        $nombre = trim($servicio->nombre);

        $variantes = [
            Str::lower($nombre),
            $this->matcher->normalizar($nombre),
            $this->matcher->normalizar(preg_replace('/^servicio\s+de\s+/iu', '', $nombre) ?? $nombre),
            $this->matcher->normalizar((string) $servicio->categoriaServicio?->nombre),
        ];

        return collect($variantes)
            ->filter(fn (string $alias): bool => $alias !== '')
            ->unique()
            ->values()
            ->all();
    }
}
