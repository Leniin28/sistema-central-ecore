<?php

namespace App\Actions\Ordenes;

use App\Actions\Servicios\MatchearServicioCatalogo;
use App\Exceptions\OrdenBloqueadaException;
use App\Models\OpenClawOrderChange;
use App\Models\OrdenServicio;
use App\Models\Servicio;
use Illuminate\Support\Facades\DB;

/**
 * Applies structured changes (services / parts / notes) from OpenClaw to a service
 * order, both when creating a reception and when editing an existing order.
 *
 * It APPENDS lines (unlike the panel's ActualizarOrdenServicio, which replaces
 * them) and recomputes totals via {@see RecalcularTotalesOrdenServicio}, the same
 * modelo_financiero-aware recompute every other update path uses. It never
 * generates finances (that only happens on delivery) and
 * refuses orders whose finances are already closed. Services must resolve to a
 * catalog entry (by explicit servicio_id or a safe name match); an unresolved
 * service is never turned into a fake billable line — it becomes a warning + note.
 * Parts accept free text.
 */
class AplicarCambiosOrdenDesdeOpenClaw
{
    public function __construct(
        private CalcularTotalesOrdenServicio $calculadora,
        private MatchearServicioCatalogo $matcher,
        private RecalcularTotalesOrdenServicio $recalcularTotales,
    ) {}

    /**
     * @param  array<string, mixed>  $ops  {servicios?, servicios_sugeridos?, refacciones?, notas?}
     * @param  string|null  $externalId  Idempotency key for the edit operation (not the order's).
     * @return array{orden: OrdenServicio, aplicado: bool, duplicado: bool, servicios_agregados: array<int, array<string, mixed>>, refacciones_agregadas: array<int, array<string, mixed>>, warnings: array<int, string>}
     */
    public function ejecutar(OrdenServicio $orden, array $ops, ?string $externalId = null): array
    {
        return DB::transaction(function () use ($orden, $ops, $externalId): array {
            $orden = OrdenServicio::query()->lockForUpdate()->findOrFail($orden->id);

            // Idempotencia de la operación de edición (solo cuando viene external_id).
            if (filled($externalId) && OpenClawOrderChange::query()->where('external_id', $externalId)->exists()) {
                return $this->resultado($orden->fresh(['cliente']), aplicado: false, duplicado: true);
            }

            if ($orden->finanzas_generadas || $orden->estado === 'entregado') {
                throw new OrdenBloqueadaException(
                    "La orden {$orden->folio} tiene finanzas cerradas y no admite cambios desde la API.",
                );
            }

            $warnings = [];
            $notasExtra = [];
            $serviciosAgregados = $this->aplicarServicios($orden, $ops['servicios'] ?? [], $warnings, $notasExtra);
            $this->registrarSugeridos($ops['servicios_sugeridos'] ?? [], $warnings, $notasExtra);
            $refaccionesAgregadas = $this->aplicarRefacciones($orden, $ops['refacciones'] ?? []);

            $bloqueNotas = $this->componerNotas($ops['notas'] ?? null, $notasExtra);
            if ($bloqueNotas !== null) {
                $orden->update([
                    'notas' => trim(($orden->notas ? $orden->notas."\n\n" : '').$bloqueNotas),
                ]);
            }

            $this->recalcularTotales->ejecutar($orden);

            if (filled($externalId)) {
                OpenClawOrderChange::create(['orden_servicio_id' => $orden->id, 'external_id' => $externalId]);
            }

            return $this->resultado(
                $orden->fresh(['cliente']),
                aplicado: true,
                duplicado: false,
                serviciosAgregados: $serviciosAgregados,
                refaccionesAgregadas: $refaccionesAgregadas,
                warnings: $warnings,
            );
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $servicios
     * @param  array<int, string>  $warnings
     * @param  array<int, string>  $notasExtra
     * @return array<int, array<string, mixed>>
     */
    private function aplicarServicios(OrdenServicio $orden, array $servicios, array &$warnings, array &$notasExtra): array
    {
        $agregados = [];

        foreach ($servicios as $servicio) {
            $descripcion = trim((string) ($servicio['descripcion'] ?? ''));
            $precioSugerido = $this->numero($servicio['precio_cliente'] ?? $servicio['precio'] ?? null);
            $resuelto = $this->resolverServicio($servicio);

            if ($resuelto instanceof Servicio) {
                $precio = $this->numero($servicio['precio_cliente'] ?? $servicio['precio'] ?? null) ?? (float) $resuelto->precio_base;
                $detalle = $orden->detalles()->create($this->calculadora->detalle([
                    'servicio_id' => $resuelto->id,
                    'descripcion' => $resuelto->nombre,
                    'cantidad' => $this->cantidad($servicio['cantidad'] ?? null),
                    'precio_unitario' => $precio,
                    'notas' => $servicio['notas'] ?? null,
                ]));

                $agregados[] = [
                    'servicio_id' => $resuelto->id,
                    'nombre' => $resuelto->nombre,
                    'cantidad' => (int) $detalle->cantidad,
                    'precio_unitario' => (float) $detalle->precio_unitario,
                    'subtotal' => (float) $detalle->subtotal,
                ];

                continue;
            }

            // Sin match claro: NO se crea línea facturable falsa (regla). Warning + nota.
            $etiqueta = $descripcion !== '' ? $descripcion : '(servicio sin descripción)';
            $warnings[] = $resuelto === 'ambiguo'
                ? "El servicio \"{$etiqueta}\" coincide con más de un servicio del catálogo; agrégalo manualmente en el panel."
                : "No se encontró en el catálogo un servicio que coincida con \"{$etiqueta}\"; no se agregó como línea. Revísalo en el panel.";
            $notasExtra[] = '- Servicio sugerido sin match: '.$etiqueta
                .($precioSugerido !== null ? ' ($'.number_format($precioSugerido, 2).')' : '');
        }

        return $agregados;
    }

    /**
     * @param  array<int, array<string, mixed>>  $sugeridos
     * @param  array<int, string>  $warnings
     * @param  array<int, string>  $notasExtra
     */
    private function registrarSugeridos(array $sugeridos, array &$warnings, array &$notasExtra): void
    {
        foreach ($sugeridos as $sugerido) {
            $descripcion = trim((string) ($sugerido['descripcion'] ?? ''));
            if ($descripcion === '') {
                continue;
            }

            $precio = $this->numero($sugerido['precio'] ?? null);
            $warnings[] = "Servicio sugerido \"{$descripcion}\" quedó como nota; confírmalo en el panel.";
            $notasExtra[] = '- Servicio sugerido: '.$descripcion
                .($precio !== null ? ' ($'.number_format($precio, 2).')' : '');
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $refacciones
     * @return array<int, array<string, mixed>>
     */
    private function aplicarRefacciones(OrdenServicio $orden, array $refacciones): array
    {
        $agregadas = [];

        foreach ($refacciones as $refaccion) {
            $descripcion = trim((string) ($refaccion['descripcion'] ?? ''));
            if ($descripcion === '') {
                continue;
            }

            $creada = $orden->refacciones()->create($this->calculadora->refaccion([
                'descripcion' => $descripcion,
                'cantidad' => $this->cantidad($refaccion['cantidad'] ?? null),
                'costo_unitario' => $this->numero($refaccion['costo_unitario'] ?? null),
                'precio_unitario_cliente' => $this->numero($refaccion['precio_cliente'] ?? $refaccion['precio'] ?? null) ?? 0.0,
                'notas' => $refaccion['notas'] ?? null,
            ]));

            $agregadas[] = [
                'descripcion' => $creada->descripcion,
                'cantidad' => (int) $creada->cantidad,
                'costo_unitario' => $creada->costo_unitario === null ? null : (float) $creada->costo_unitario,
                'precio_unitario_cliente' => (float) $creada->precio_unitario_cliente,
                'precio_total_cliente' => (float) $creada->precio_total_cliente,
            ];
        }

        return $agregadas;
    }

    /**
     * @param  array<string, mixed>  $servicio
     * @return Servicio|string|null Servicio si hay match único; 'ambiguo' si varios; null si ninguno.
     */
    private function resolverServicio(array $servicio): Servicio|string|null
    {
        if (! empty($servicio['servicio_id'])) {
            $encontrado = Servicio::query()->whereKey($servicio['servicio_id'])->where('activo', true)->first();

            return $encontrado ?: null; // id inexistente/inactivo → warning, sin línea falsa.
        }

        $descripcion = trim((string) ($servicio['descripcion'] ?? ''));

        // Match estricto contra el catálogo activo actual (compartido con
        // /services/match): único → línea real; ambiguo/ninguno → warning.
        return $descripcion !== '' ? $this->matcher->matchUnico($descripcion) : null;
    }

    private function numero(mixed $valor): ?float
    {
        return $valor === null || $valor === '' ? null : max(0.0, (float) $valor);
    }

    private function cantidad(mixed $valor): int
    {
        return max(1, (int) ($valor ?? 1));
    }

    /**
     * @param  array<int, string>  $notasExtra
     */
    private function componerNotas(?string $notas, array $notasExtra): ?string
    {
        $bloques = [];

        if (filled($notas)) {
            $bloques[] = 'Nota (Telegram): '.trim($notas);
        }
        if ($notasExtra !== []) {
            $bloques[] = "Sugerencias sin aplicar:\n".implode("\n", $notasExtra);
        }

        return $bloques !== [] ? implode("\n\n", $bloques) : null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $serviciosAgregados
     * @param  array<int, array<string, mixed>>  $refaccionesAgregadas
     * @param  array<int, string>  $warnings
     * @return array{orden: OrdenServicio, aplicado: bool, duplicado: bool, servicios_agregados: array<int, array<string, mixed>>, refacciones_agregadas: array<int, array<string, mixed>>, warnings: array<int, string>}
     */
    private function resultado(
        OrdenServicio $orden,
        bool $aplicado,
        bool $duplicado,
        array $serviciosAgregados = [],
        array $refaccionesAgregadas = [],
        array $warnings = [],
    ): array {
        return [
            'orden' => $orden,
            'aplicado' => $aplicado,
            'duplicado' => $duplicado,
            'servicios_agregados' => $serviciosAgregados,
            'refacciones_agregadas' => $refaccionesAgregadas,
            'warnings' => $warnings,
        ];
    }
}
