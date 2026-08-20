<?php

namespace App\Actions\Cotizaciones;

use App\Actions\OpenClaw\ObtenerUsuarioSistema;
use App\Actions\OpenClaw\ResolverPartnerLogistico;
use App\Actions\Ordenes\CrearOrdenServicio;
use App\Models\Cotizacion;
use App\Models\Equipo;
use App\Models\OrdenServicio;
use Illuminate\Support\Facades\DB;

/**
 * Converts an accepted quote into an order. Quote conversion deliberately does
 * not use the OpenClaw /changes matcher: an accepted quote is the commercial
 * source of truth and its sold lines may be ad-hoc.
 */
class ConvertirCotizacionEnOrden
{
    public function __construct(
        private CrearOrdenServicio $crearOrden,
        private ObtenerUsuarioSistema $usuarioSistema,
        private ResolverPartnerLogistico $resolverPartner,
        private VincularCotizacionAOrden $vincularOrden,
        private SincronizarLineasCotizacionConOrden $sincronizarLineas,
        private RegistrarAnticipoCotizacion $registrarAnticipo,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array{orden: OrdenServicio, cotizacion: Cotizacion, created: bool, warnings: array<int, string>}
     */
    public function ejecutar(Cotizacion $cotizacion, array $data): array
    {
        return DB::transaction(function () use ($cotizacion, $data): array {
            $cotizacion = Cotizacion::query()->lockForUpdate()->findOrFail($cotizacion->id);
            $cotizacion->asegurarOperativa();
            $cotizacion->loadMissing(['cliente', 'equipo', 'items']);

            $ordenVinculada = $cotizacion->ordenServicio()->lockForUpdate()->first();
            if ($ordenVinculada) {
                $this->vincularOrden->asegurarElegible($ordenVinculada, $cotizacion, exigirEquipo: false);
                $this->registrarAnticipo->vincularAOrden($cotizacion, $ordenVinculada);
                $this->sincronizarLineas->sincronizar($ordenVinculada, $cotizacion);

                return [
                    'orden' => $ordenVinculada->fresh(['cliente', 'equipo', 'partnerRecepcion']),
                    'cotizacion' => $cotizacion,
                    'created' => false,
                    'warnings' => [],
                ];
            }

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
            $origen = (string) ($data['origen'] ?? 'openclaw-cotizacion');
            $origenNota = (string) ($data['origen_nota'] ?? 'OpenClaw');
            $bloques = [
                'Falla reportada:'."\n".($falla !== '' ? $falla : 'Servicio autorizado desde cotización '.$cotizacion->folio),
                "Origen:\nConvertida desde la cotización {$cotizacion->folio} por OpenClaw.",
            ];
            $bloques[1] = "Origen:\nConvertida desde la cotización {$cotizacion->folio} por {$origenNota}.";

            if (filled($recepcion['notas'] ?? null)) {
                $bloques[] = "Notas:\n".trim((string) $recepcion['notas']);
            }

            $orden = $this->crearOrden->ejecutar(
                [
                    'cotizacion_id' => $cotizacion->id,
                    'cliente_id' => $cotizacion->cliente_id,
                    'equipo_id' => $equipo?->id,
                    'partner_recepcion_id' => $partner,
                    'tipo_recepcion' => 'directo',
                    'notas' => implode("\n\n", $bloques),
                    'costo_tecnico' => null,
                    'external_id' => $data['external_id'] ?? null,
                    'origen' => $origen,
                ],
                [],
                [],
                $data['actor'] ?? $this->usuarioSistema->ejecutar(),
            );

            $this->sincronizarLineas->sincronizar($orden, $cotizacion);
            $this->registrarAnticipo->vincularAOrden($cotizacion, $orden);

            if ($cotizacion->estado !== 'aceptada') {
                if ($cotizacion->esEditable()) {
                    $cotizacion->update(['estado' => 'aceptada']);
                } else {
                    $warnings[] = "La cotización {$cotizacion->folio} está en estado \"{$cotizacion->estado}\" y no se cambió a aceptada.";
                }
            }

            $cotizacion->update([
                'notas' => trim(((string) $cotizacion->notas ? $cotizacion->notas."\n\n" : '')
                    ."Convertida en la orden {$orden->folio} por OpenClaw."),
            ]);

            if ($origen !== 'openclaw-cotizacion') {
                $cotizacion->update([
                    'notas' => str_replace(' por OpenClaw.', " por {$origenNota}.", (string) $cotizacion->notas),
                ]);
            }

            return [
                'orden' => $orden->fresh(['cliente', 'equipo', 'partnerRecepcion']),
                'cotizacion' => $cotizacion->fresh(),
                'created' => true,
                'warnings' => $warnings,
            ];
        });
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
