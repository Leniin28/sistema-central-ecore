<?php

namespace App\Actions\Cotizaciones;

use App\Actions\OpenClaw\ObtenerUsuarioSistema;
use App\Actions\OpenClaw\ResolverPartnerLogistico;
use App\Actions\Ordenes\AplicarCambiosOrdenDesdeOpenClaw;
use App\Actions\Ordenes\CrearOrdenServicio;
use App\Models\Cotizacion;
use App\Models\Equipo;
use App\Models\OrdenServicio;
use Illuminate\Support\Facades\DB;

/**
 * Converts an accepted quote into a service order from the internal API
 * (OpenClaw). Reuses the real order flow ({@see CrearOrdenServicio} + the same
 * system actor) and the safe line mapper ({@see AplicarCambiosOrdenDesdeOpenClaw}):
 * quote items of type servicio must match the catalog to become billable lines
 * (otherwise warning + note), while refaccion/producto/otro items become free
 * text part lines — nothing is invented into the catalog. The schema has no
 * cotizacion_id on orders, so the link lives in both notes; the quote is moved
 * to `aceptada` and the order is idempotent by external_id.
 */
class ConvertirCotizacionEnOrden
{
    public function __construct(
        private CrearOrdenServicio $crearOrden,
        private AplicarCambiosOrdenDesdeOpenClaw $aplicarCambios,
        private ObtenerUsuarioSistema $usuarioSistema,
        private ResolverPartnerLogistico $resolverPartner,
    ) {}

    /**
     * @param  array<string, mixed>  $data  Server-validated payload.
     * @return array{orden: OrdenServicio, cotizacion: Cotizacion, created: bool, warnings: array<int, string>}
     */
    public function ejecutar(Cotizacion $cotizacion, array $data): array
    {
        return DB::transaction(function () use ($cotizacion, $data): array {
            $cotizacion = Cotizacion::query()->lockForUpdate()->findOrFail($cotizacion->id);
            $cotizacion->loadMissing(['cliente', 'equipo', 'items']);

            // Idempotencia: mismo external_id → misma orden, sin duplicar.
            if (! empty($data['external_id'])) {
                $existente = OrdenServicio::query()->where('external_id', $data['external_id'])->first();

                if ($existente) {
                    return [
                        'orden' => $existente->loadMissing(['cliente', 'equipo', 'partnerRecepcion']),
                        'cotizacion' => $cotizacion,
                        'created' => false,
                        'warnings' => [],
                    ];
                }
            }

            $warnings = [];
            $equipo = $this->resolverEquipo($cotizacion, $data, $warnings);
            $partner = $this->resolverPartnerLogistico($data, $warnings);

            $recepcion = $data['recepcion'] ?? [];
            $falla = trim((string) ($recepcion['falla_reportada'] ?? ''));
            $bloques = [
                'Falla reportada:'."\n".($falla !== '' ? $falla : 'Servicio autorizado desde cotización '.$cotizacion->folio),
                "Origen:\nConvertida desde la cotización {$cotizacion->folio} por OpenClaw.",
            ];
            if (filled($recepcion['notas'] ?? null)) {
                $bloques[] = "Notas:\n".trim((string) $recepcion['notas']);
            }

            $orden = $this->crearOrden->ejecutar(
                [
                    'cliente_id' => $cotizacion->cliente_id,
                    'equipo_id' => $equipo?->id,
                    'partner_recepcion_id' => $partner,
                    'tipo_recepcion' => 'directo',
                    'notas' => implode("\n\n", $bloques),
                    'costo_tecnico' => 0,
                    'external_id' => $data['external_id'] ?? null,
                    'origen' => 'openclaw-cotizacion',
                ],
                [],
                [],
                $this->usuarioSistema->ejecutar(),
            );

            // Items → líneas con las mismas reglas seguras del endpoint /changes.
            $cambios = $this->aplicarCambios->ejecutar($orden, $this->mapearItems($cotizacion), null);
            $warnings = [...$warnings, ...$cambios['warnings']];

            // Marcar la cotización como aceptada (solo si aún no lo está).
            if ($cotizacion->estado !== 'aceptada') {
                if ($cotizacion->esEditable()) {
                    $cotizacion->update(['estado' => 'aceptada']);
                } else {
                    $warnings[] = "La cotización {$cotizacion->folio} está en estado \"{$cotizacion->estado}\" y no se cambió a aceptada.";
                }
            }

            // El esquema no tiene FK orden↔cotización: se documenta en ambas notas.
            $cotizacion->update([
                'notas' => trim(((string) $cotizacion->notas ? $cotizacion->notas."\n\n" : '')
                    ."Convertida en la orden {$cambios['orden']->folio} por OpenClaw."),
            ]);

            return [
                'orden' => $cambios['orden']->loadMissing(['cliente', 'equipo', 'partnerRecepcion']),
                'cotizacion' => $cotizacion->fresh(),
                'created' => true,
                'warnings' => $warnings,
            ];
        });
    }

    /**
     * Quote items to /changes-style ops: servicios go through the catalog match
     * (warning + note when unresolved), everything else becomes a part line with
     * the quoted price (cost unknown → 0, panel captures it later).
     *
     * @return array<string, mixed>
     */
    private function mapearItems(Cotizacion $cotizacion): array
    {
        $servicios = [];
        $refacciones = [];

        foreach ($cotizacion->items as $item) {
            if ($item->tipo === 'servicio') {
                $servicios[] = [
                    'descripcion' => $item->descripcion,
                    'cantidad' => (int) $item->cantidad,
                    'precio_cliente' => (float) $item->precio_unitario,
                ];

                continue;
            }

            $refacciones[] = [
                'descripcion' => $item->descripcion,
                'cantidad' => (int) $item->cantidad,
                'precio_cliente' => (float) $item->precio_unitario,
                'costo_unitario' => 0,
                'notas' => 'Tomado de la cotización '.$cotizacion->folio.' (tipo '.$item->tipo.').',
            ];
        }

        return ['servicios' => $servicios, 'refacciones' => $refacciones];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, string>  $warnings
     */
    private function resolverEquipo(Cotizacion $cotizacion, array $data, array &$warnings): ?Equipo
    {
        if ($cotizacion->equipo) {
            return $cotizacion->equipo;
        }

        $nuevo = $data['equipo'] ?? [];
        if (blank($nuevo['tipo_equipo'] ?? null) && blank($nuevo['modelo'] ?? null)) {
            $warnings[] = 'La cotización no tiene equipo y no se envió uno; la orden quedó sin equipo. Asígnalo en el panel.';

            return null;
        }

        return Equipo::create([
            'cliente_id' => $cotizacion->cliente_id,
            'tipo_equipo' => $nuevo['tipo_equipo'] ?? $nuevo['modelo'] ?? 'Equipo',
            'marca' => $nuevo['marca'] ?? 'Sin marca',
            'modelo' => $nuevo['modelo'] ?? null,
            'numero_serie' => $nuevo['numero_serie'] ?? null,
            'password_equipo' => $nuevo['password_equipo'] ?? null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, string>  $warnings
     */
    private function resolverPartnerLogistico(array $data, array &$warnings): ?int
    {
        if (! empty($data['partner_recepcion_id'])) {
            return (int) $data['partner_recepcion_id'];
        }

        if (blank($data['partner_logistico'] ?? null)) {
            return null;
        }

        $resultado = $this->resolverPartner->ejecutar((string) $data['partner_logistico']);
        if ($resultado['warning'] !== null) {
            $warnings[] = $resultado['warning'];
        }

        return $resultado['id'];
    }
}
