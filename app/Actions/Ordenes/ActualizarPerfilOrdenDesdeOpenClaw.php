<?php

namespace App\Actions\Ordenes;

use App\Actions\OpenClaw\ResolverPartnerLogistico;
use App\Exceptions\OrdenBloqueadaException;
use App\Models\OpenClawOrderChange;
use App\Models\OrdenServicio;
use Illuminate\Support\Facades\DB;

/**
 * Applies base-data corrections from OpenClaw (vision misreads on the label
 * photo, follow-up Telegram messages) to an existing order: client, equipment,
 * reception metadata and logistics partner. It UPDATES the related client and
 * equipment in place — the same records the panel edits — instead of creating
 * duplicates, appends an audit note to the order, and never touches lines,
 * totals or finances. Closed orders (delivered / finances generated) are
 * rejected, and idempotency reuses the shared openclaw_order_changes ledger.
 */
class ActualizarPerfilOrdenDesdeOpenClaw
{
    public function __construct(private ResolverPartnerLogistico $resolverPartner) {}

    /**
     * @param  array<string, mixed>  $data  Server-validated payload.
     * @return array{orden: OrdenServicio, aplicado: bool, duplicado: bool, cambios: array<int, string>, warnings: array<int, string>}
     */
    public function ejecutar(OrdenServicio $orden, array $data, ?string $externalId = null): array
    {
        return DB::transaction(function () use ($orden, $data, $externalId): array {
            $orden = OrdenServicio::query()->lockForUpdate()->findOrFail($orden->id);

            if (filled($externalId) && OpenClawOrderChange::query()->where('external_id', $externalId)->exists()) {
                return $this->resultado($orden->fresh(['cliente', 'equipo', 'partnerRecepcion']), aplicado: false, duplicado: true);
            }

            if ($orden->finanzas_generadas || $orden->estado === 'entregado') {
                throw new OrdenBloqueadaException(
                    "La orden {$orden->folio} tiene finanzas cerradas y no admite correcciones desde la API.",
                );
            }

            $warnings = [];
            $cambios = [];

            $this->corregirCliente($orden, $data['cliente'] ?? [], $cambios);
            $this->corregirEquipo($orden, $data['equipo'] ?? [], $cambios);
            $this->corregirPartner($orden, $data, $cambios, $warnings);
            $notaRecepcion = $this->notaRecepcion($data['recepcion'] ?? [], $cambios);

            if ($cambios === []) {
                $warnings[] = 'No se recibió ningún dato para corregir; la orden quedó igual.';
            }

            // Nota de auditoría: qué se corrigió y que vino de OpenClaw. Nunca
            // se escribe el valor de password_equipo.
            if ($cambios !== [] || $notaRecepcion !== null) {
                $bloques = [];
                if ($notaRecepcion !== null) {
                    $bloques[] = $notaRecepcion;
                }
                if ($cambios !== []) {
                    $bloques[] = 'Corrección vía OpenClaw (Telegram): '.implode(', ', $cambios).'.';
                }
                $orden->update([
                    'notas' => trim(($orden->notas ? $orden->notas."\n\n" : '').implode("\n\n", $bloques)),
                ]);
            }

            if (filled($externalId)) {
                OpenClawOrderChange::create(['orden_servicio_id' => $orden->id, 'external_id' => $externalId]);
            }

            return $this->resultado(
                $orden->fresh(['cliente', 'equipo', 'partnerRecepcion']),
                aplicado: $cambios !== [],
                duplicado: false,
                cambios: $cambios,
                warnings: $warnings,
            );
        });
    }

    /**
     * @param  array<string, mixed>  $cliente
     * @param  array<int, string>  $cambios
     */
    private function corregirCliente(OrdenServicio $orden, array $cliente, array &$cambios): void
    {
        $relacionado = $orden->cliente;
        if ($relacionado === null || $cliente === []) {
            return;
        }

        $atributos = [];
        if (filled($cliente['nombre'] ?? null) && $cliente['nombre'] !== $relacionado->nombre) {
            $atributos['nombre'] = trim((string) $cliente['nombre']);
            $cambios[] = "cliente.nombre → \"{$atributos['nombre']}\"";
        }
        if (filled($cliente['telefono'] ?? null) && $cliente['telefono'] !== $relacionado->telefono) {
            $atributos['telefono'] = trim((string) $cliente['telefono']);
            $cambios[] = "cliente.telefono → \"{$atributos['telefono']}\"";
        }
        if (filled($cliente['correo'] ?? null) && $cliente['correo'] !== $relacionado->correo) {
            $atributos['correo'] = trim((string) $cliente['correo']);
            $cambios[] = "cliente.correo → \"{$atributos['correo']}\"";
        }

        if ($atributos !== []) {
            $relacionado->update($atributos);
        }
    }

    /**
     * @param  array<string, mixed>  $equipo
     * @param  array<int, string>  $cambios
     */
    private function corregirEquipo(OrdenServicio $orden, array $equipo, array &$cambios): void
    {
        $relacionado = $orden->equipo;
        if ($relacionado === null || $equipo === []) {
            return;
        }

        $atributos = [];
        foreach (['tipo_equipo', 'marca', 'modelo', 'numero_serie'] as $campo) {
            if (filled($equipo[$campo] ?? null) && $equipo[$campo] !== $relacionado->{$campo}) {
                $atributos[$campo] = trim((string) $equipo[$campo]);
                $cambios[] = "equipo.{$campo} → \"{$atributos[$campo]}\"";
            }
        }

        // La contraseña se registra pero jamás se refleja en notas ni respuestas.
        if (filled($equipo['password_equipo'] ?? null) && $equipo['password_equipo'] !== $relacionado->password_equipo) {
            $atributos['password_equipo'] = (string) $equipo['password_equipo'];
            $cambios[] = 'equipo.password_equipo actualizada';
        }

        if ($atributos !== []) {
            $relacionado->update($atributos);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, string>  $cambios
     * @param  array<int, string>  $warnings
     */
    private function corregirPartner(OrdenServicio $orden, array $data, array &$cambios, array &$warnings): void
    {
        if (! empty($data['partner_recepcion_id'])) {
            // Ya validado por el controller como partner logístico activo.
            if ((int) $data['partner_recepcion_id'] !== $orden->partner_recepcion_id) {
                $orden->update(['partner_recepcion_id' => (int) $data['partner_recepcion_id']]);
                $orden->load('partnerRecepcion');
                $cambios[] = 'partner_recepcion → "'.$orden->partnerRecepcion?->nombre.'"';
            }

            return;
        }

        if (blank($data['partner_logistico'] ?? null)) {
            return;
        }

        $resultado = $this->resolverPartner->ejecutar((string) $data['partner_logistico']);

        if ($resultado['warning'] !== null) {
            // Sin match: warning, la orden conserva su partner actual.
            $warnings[] = $resultado['warning'];

            return;
        }

        if ($resultado['id'] !== null && $resultado['id'] !== $orden->partner_recepcion_id) {
            $orden->update(['partner_recepcion_id' => $resultado['id']]);
            $orden->load('partnerRecepcion');
            $cambios[] = 'partner_recepcion → "'.$orden->partnerRecepcion?->nombre.'"';
        }
    }

    /**
     * The order has no dedicated columns for the reception metadata (fault,
     * external folio, label date): the reception flow folds them into the notes,
     * so corrections are appended the same way.
     *
     * @param  array<string, mixed>  $recepcion
     * @param  array<int, string>  $cambios
     */
    private function notaRecepcion(array $recepcion, array &$cambios): ?string
    {
        $lineas = [];

        if (filled($recepcion['falla_reportada'] ?? null)) {
            $lineas[] = 'Falla reportada (corregida): '.trim((string) $recepcion['falla_reportada']);
            $cambios[] = 'falla_reportada corregida';
        }
        if (filled($recepcion['folio_externo'] ?? null)) {
            $lineas[] = 'Folio externo (corregido): '.trim((string) $recepcion['folio_externo']);
            $cambios[] = 'folio_externo corregido';
        }
        if (filled($recepcion['fecha_etiqueta'] ?? null)) {
            $lineas[] = 'Fecha en etiqueta (corregida): '.trim((string) $recepcion['fecha_etiqueta']);
            $cambios[] = 'fecha_etiqueta corregida';
        }
        if (filled($recepcion['notas'] ?? null)) {
            $lineas[] = 'Notas de recepción (OpenClaw): '.trim((string) $recepcion['notas']);
            $cambios[] = 'notas de recepción agregadas';
        }

        return $lineas !== [] ? implode("\n", $lineas) : null;
    }

    /**
     * @param  array<int, string>  $cambios
     * @param  array<int, string>  $warnings
     * @return array{orden: OrdenServicio, aplicado: bool, duplicado: bool, cambios: array<int, string>, warnings: array<int, string>}
     */
    private function resultado(
        OrdenServicio $orden,
        bool $aplicado,
        bool $duplicado,
        array $cambios = [],
        array $warnings = [],
    ): array {
        return [
            'orden' => $orden,
            'aplicado' => $aplicado,
            'duplicado' => $duplicado,
            'cambios' => $cambios,
            'warnings' => $warnings,
        ];
    }
}
