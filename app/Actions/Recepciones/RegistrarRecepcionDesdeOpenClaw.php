<?php

namespace App\Actions\Recepciones;

use App\Actions\Ordenes\CrearOrdenServicio;
use App\Models\Cliente;
use App\Models\Equipo;
use App\Models\OrdenServicio;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
    public function __construct(private CrearOrdenServicio $crearOrden) {}

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
                    ];
                }
            }

            $cliente = $this->resolverCliente($data);
            $equipo = $this->crearEquipo($data, $cliente);
            [$notas, $warnings] = $this->componerNotas($data);

            $orden = $this->crearOrden->ejecutar(
                [
                    'cliente_id' => $cliente->id,
                    'equipo_id' => $equipo->id,
                    'tipo_recepcion' => $data['tipo_recepcion'] ?? 'directo',
                    'notas' => $notas,
                    'costo_tecnico' => 0,
                    'external_id' => $data['external_id'] ?? null,
                    'origen' => $data['recepcion']['origen'] ?? 'openclaw',
                ],
                [], // servicios: sin servicio_id de catálogo → no se crean detalles facturables.
                [], // refacciones: se dejan como texto sugerido (ver componerNotas()).
                $this->usuarioSistema(),
            );

            return [
                'orden' => $orden->loadMissing(['cliente', 'equipo']),
                'created' => true,
                'warnings' => $warnings,
            ];
        });
    }

    /**
     * The internal API has no web session, but orders require a non-null creator
     * (FK restrictOnDelete). We attribute them to a dedicated system user with the
     * admin role (so CrearOrdenServicio's authorization passes) and an unusable
     * random password. It is created lazily once; pre-seed it in production.
     *
     * @param array<string, mixed> $data
     */
    private function usuarioSistema(): User
    {
        $email = (string) config('services.openclaw.system_user_email', 'openclaw-bot@sistema.local');

        return User::firstOrCreate(
            ['email' => $email],
            [
                'name' => 'OpenClaw (sistema)',
                'password' => bcrypt(Str::random(64)),
                'role' => 'admin',
            ],
        );
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
     * @return array{0: string, 1: array<int, string>}
     */
    private function componerNotas(array $data): array
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
        if ($meta !== []) {
            $bloques[] = "Datos de etiqueta:\n".implode("\n", $meta);
        }

        $servicios = $this->lineasSugeridas($data['servicios'] ?? []);
        if ($servicios !== []) {
            $bloques[] = "Servicios sugeridos (de etiqueta, aún no cargados al catálogo):\n".implode("\n", $servicios);
            $warnings[] = 'Los servicios llegaron como texto libre; cárgalos desde el catálogo con su precio en el panel para poder facturarlos.';
        }

        $refacciones = $this->lineasSugeridas($data['refacciones'] ?? []);
        if ($refacciones !== []) {
            $bloques[] = "Refacciones sugeridas (de etiqueta):\n".implode("\n", $refacciones);
            $warnings[] = 'Las refacciones llegaron como texto libre; cárgalas en la orden con costo y precio para que se reflejen en finanzas.';
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

    /**
     * @param  array<int, array<string, mixed>>  $filas
     * @return array<int, string>
     */
    private function lineasSugeridas(array $filas): array
    {
        return collect($filas)
            ->filter(fn ($fila): bool => is_array($fila) && filled($fila['descripcion'] ?? null))
            ->map(function (array $fila): string {
                $linea = '- '.trim((string) $fila['descripcion']);

                if (isset($fila['precio']) && $fila['precio'] !== null) {
                    $linea .= ' ($'.number_format((float) $fila['precio'], 2).')';
                }

                return $linea;
            })
            ->values()
            ->all();
    }
}
