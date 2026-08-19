<?php

namespace App\Actions\Recepciones;

use App\Actions\OpenClaw\ObtenerUsuarioSistema;
use App\Actions\OpenClaw\ResolverPartnerLogistico;
use App\Actions\Ordenes\AplicarCambiosOrdenDesdeOpenClaw;
use App\Actions\Ordenes\CrearOrdenServicio;
use App\Models\Cliente;
use App\Models\Equipo;
use App\Models\OrdenServicio;
use Illuminate\Support\Facades\DB;

/**
 * Registers a reception/service order from data OpenClaw extracted from a photo
 * of a physical label sent by Telegram. Reuses {@see CrearOrdenServicio} for the
 * order itself (folio, status history, transaction) and only adds the parts that
 * are specific to the internal API: quick client/equipment upsert, a system
 * actor (orders require a real creator), idempotency by external_id, and folding
 * the low-confidence label data (suggested services/parts) into the order notes.
 *
 * It intentionally does NOT create billable line items: services extracted from a
 * label have no catalog servicio_id and no verified pricing, so they are stored as
 * text and surfaced as warnings for staff to confirm in the panel. total_cliente
 * stays 0 and no financial movements are generated.
 */
class RegistrarRecepcionDesdeOpenClaw
{
    public function __construct(
        private CrearOrdenServicio $crearOrden,
        private AplicarCambiosOrdenDesdeOpenClaw $aplicarCambios,
        private ObtenerUsuarioSistema $usuarioSistema,
        private ResolverPartnerLogistico $resolverPartner,
    ) {}

    /**
     * @param  array<string, mixed>  $data  Server-validated payload.
     * @return array{orden: OrdenServicio, created: bool, warnings: array<int, string>}
     */
    public function ejecutar(array $data): array
    {
        return DB::transaction(function () use ($data): array {
            // Idempotency: a repeated external_id (e.g. Telegram message id) returns
            // the order already created instead of duplicating it.
            if (! empty($data['external_id'])) {
                $existente = OrdenServicio::query()->where('external_id', $data['external_id'])->first();

                if ($existente) {
                    return [
                        'orden' => $existente->loadMissing(['cliente', 'equipo']),
                        'created' => false,
                        'warnings' => [],
                        'servicios_agregados' => [],
                        'refacciones_agregadas' => [],
                    ];
                }
            }

            $cliente = $this->resolverCliente($data);
            $equipo = $this->crearEquipo($data, $cliente);
            $partner = $this->resolverPartnerLogistico($data);
            [$notas, $warnings] = $this->componerNotas($data, $partner['texto']);

            if ($partner['warning'] !== null) {
                $warnings[] = $partner['warning'];
            }

            $orden = $this->crearOrden->ejecutar(
                [
                    'cliente_id' => $cliente->id,
                    'equipo_id' => $equipo->id,
                    // El partner resuelto (o null) lo revalida CrearOrdenServicio::prepararAsignaciones.
                    'partner_recepcion_id' => $partner['id'],
                    'tipo_recepcion' => $data['tipo_recepcion'] ?? 'directo',
                    'notas' => $notas,
                    'costo_tecnico' => null,
                    'external_id' => $data['external_id'] ?? null,
                    'origen' => $data['recepcion']['origen'] ?? 'openclaw',
                ],
                [], // Las líneas reales se agregan abajo con AplicarCambiosOrdenDesdeOpenClaw.
                [],
                $this->usuarioSistema->ejecutar(),
            );

            // Servicios (match de catálogo) y refacciones (texto libre) como líneas reales.
            // La idempotencia de la recepción ya la cubre external_id de la orden → aquí null.
            $cambios = $this->aplicarCambios->ejecutar($orden, [
                'servicios' => $data['servicios'] ?? [],
                'refacciones' => $data['refacciones'] ?? [],
            ], null);

            return [
                'orden' => $cambios['orden']->loadMissing(['cliente', 'equipo']),
                'created' => true,
                'warnings' => array_merge($warnings, $cambios['warnings']),
                'servicios_agregados' => $cambios['servicios_agregados'],
                'refacciones_agregadas' => $cambios['refacciones_agregadas'],
            ];
        });
    }

    /** @param array<string, mixed> $data */
    private function resolverCliente(array $data): Cliente
    {
        if (! empty($data['cliente_id'])) {
            return Cliente::query()->findOrFail($data['cliente_id']);
        }

        $nuevo = $data['cliente'];

        return Cliente::create([
            'nombre' => $nuevo['nombre'],
            'telefono' => $this->normalizarTelefono($nuevo['telefono'] ?? null) ?? 'Sin teléfono',
            'correo' => $nuevo['correo'] ?? null,
            'tipo_cliente' => $nuevo['tipo_cliente'] ?? 'mantenimiento',
        ]);
    }

    /** @param array<string, mixed> $data */
    private function crearEquipo(array $data, Cliente $cliente): Equipo
    {
        $equipo = $data['equipo'];

        return Equipo::create([
            'cliente_id' => $cliente->id,
            // tipo_equipo y marca son NOT NULL; la validación garantiza tipo_equipo o modelo.
            'tipo_equipo' => $equipo['tipo_equipo'] ?? $equipo['modelo'] ?? 'Equipo',
            'marca' => $equipo['marca'] ?? 'Sin marca',
            'modelo' => $equipo['modelo'] ?? null,
            'numero_serie' => $equipo['numero_serie'] ?? null,
            'password_equipo' => $equipo['password_equipo'] ?? null,
        ]);
    }

    /**
     * Resolves the logistics partner (branch) detected on the label. Order of
     * preference: explicit partner_recepcion_id (already validated as an active
     * logistics partner by the controller), then the shared tolerant name match
     * ({@see ResolverPartnerLogistico}). A miss or an ambiguous match never fails
     * the reception: it returns a warning and the detected text so it can be
     * kept in the notes.
     *
     * @param  array<string, mixed>  $data
     * @return array{id: int|null, warning: string|null, texto: string|null}
     */
    private function resolverPartnerLogistico(array $data): array
    {
        $recepcion = $data['recepcion'] ?? [];

        $explicitId = $data['partner_recepcion_id'] ?? ($recepcion['partner_recepcion_id'] ?? null);
        if (! empty($explicitId)) {
            return ['id' => (int) $explicitId, 'warning' => null, 'texto' => null];
        }

        return $this->resolverPartner->ejecutar($this->primerTextoLleno([
            $recepcion['partner_logistico'] ?? null,
            $recepcion['partner_logistico_nombre'] ?? null,
            $recepcion['sucursal_nombre'] ?? null,
            $data['partner_logistico'] ?? null,
        ]));
    }

    /**
     * @param  array<int, mixed>  $valores
     */
    private function primerTextoLleno(array $valores): ?string
    {
        foreach ($valores as $valor) {
            if (is_string($valor) && trim($valor) !== '') {
                return trim($valor);
            }
        }

        return null;
    }

    private function normalizarTelefono(?string $telefono): ?string
    {
        if (blank($telefono)) {
            return null;
        }

        $digitos = preg_replace('/\D+/', '', $telefono);

        return $digitos !== '' ? $digitos : null;
    }

    /**
     * Builds the order note from the label data and returns any low-confidence
     * warnings. Suggested services/parts are kept as text (not billable rows).
     *
     * @param  array<string, mixed>  $data
     * @param  string|null  $sucursalTexto  Texto de sucursal/partner detectado en la etiqueta.
     * @return array{0: string, 1: array<int, string>}
     */
    private function componerNotas(array $data, ?string $sucursalTexto = null): array
    {
        $warnings = [];
        $recepcion = $data['recepcion'] ?? [];
        $bloques = [];

        $falla = trim((string) ($recepcion['falla_reportada'] ?? ''));
        if ($falla === '') {
            $warnings[] = 'No se recibió falla_reportada; captúrala manualmente en el panel.';
            $bloques[] = "Falla reportada:\n(no especificada en la etiqueta)";
        } else {
            $bloques[] = "Falla reportada:\n{$falla}";
        }

        $meta = [];
        if (filled($recepcion['fecha_etiqueta'] ?? null)) {
            $meta[] = "Fecha en etiqueta: {$recepcion['fecha_etiqueta']}";
        }
        if (filled($recepcion['folio_externo'] ?? null)) {
            $meta[] = "Folio externo (nota física): {$recepcion['folio_externo']}";
        }
        if (filled($recepcion['origen'] ?? null)) {
            $meta[] = "Origen: {$recepcion['origen']}";
        }
        if ($sucursalTexto !== null) {
            $meta[] = "Sucursal/partner detectado: {$sucursalTexto}";
        }
        if ($meta !== []) {
            $bloques[] = "Datos de etiqueta:\n".implode("\n", $meta);
        }

        if (filled($recepcion['notas'] ?? null)) {
            $bloques[] = 'Notas de recepción:\n'.trim((string) $recepcion['notas']);
        }
        if (filled($data['notas'] ?? null)) {
            $bloques[] = 'Notas adicionales:\n'.trim((string) $data['notas']);
        }

        $bloques[] = 'Registrado automáticamente por OpenClaw a partir de una foto de etiqueta física.';

        return [implode("\n\n", $bloques), $warnings];
    }
}
