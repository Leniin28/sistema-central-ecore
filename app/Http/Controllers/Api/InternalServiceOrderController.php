<?php

namespace App\Http\Controllers\Api;

use App\Actions\Ordenes\ActualizarPerfilOrdenDesdeOpenClaw;
use App\Actions\Ordenes\AplicarCambiosOrdenDesdeOpenClaw;
use App\Actions\Ordenes\CambiarEstadoOrdenDesdeOpenClaw;
use App\Exceptions\ConfirmacionEntregaRequeridaException;
use App\Exceptions\OrdenBloqueadaException;
use App\Http\Controllers\Controller;
use App\Models\OrdenServicio;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class InternalServiceOrderController extends Controller
{
    /**
     * Show a service order (by numeric id or folio) with a safe payload for
     * OpenClaw: full lines and totals, but never password_equipo — only the
     * boolean password_registrada.
     */
    public function show(string $orden): JsonResponse
    {
        $ordenServicio = $this->resolverOrden($orden);
        abort_if($ordenServicio === null, 404, 'Orden de servicio no encontrada.');

        $ordenServicio->loadMissing([
            'cliente', 'equipo', 'partnerRecepcion', 'partnerTecnico', 'detalles.servicio', 'refacciones',
        ]);

        return response()->json($this->payloadOrden($ordenServicio));
    }

    /**
     * Correct base data of an order from OpenClaw (client, equipment, reception
     * metadata, logistics partner). Meant for vision misreads: it updates the
     * related records in place, never touches lines/totals/finances, and rejects
     * closed orders.
     */
    public function profile(Request $request, string $orden, ActualizarPerfilOrdenDesdeOpenClaw $accion): JsonResponse
    {
        $ordenServicio = $this->resolverOrden($orden);
        abort_if($ordenServicio === null, 404, 'Orden de servicio no encontrada.');

        $data = $request->validate([
            'cliente' => ['nullable', 'array'],
            'cliente.nombre' => ['nullable', 'string', 'max:255'],
            'cliente.telefono' => ['nullable', 'string', 'max:50'],
            'cliente.correo' => ['nullable', 'email', 'max:255'],

            'equipo' => ['nullable', 'array'],
            'equipo.tipo_equipo' => ['nullable', 'string', 'max:100'],
            'equipo.marca' => ['nullable', 'string', 'max:100'],
            'equipo.modelo' => ['nullable', 'string', 'max:100'],
            'equipo.numero_serie' => ['nullable', 'string', 'max:150'],
            'equipo.password_equipo' => ['nullable', 'string', 'max:150'],

            'recepcion' => ['nullable', 'array'],
            'recepcion.falla_reportada' => ['nullable', 'string', 'max:3000'],
            'recepcion.notas' => ['nullable', 'string', 'max:3000'],
            'recepcion.folio_externo' => ['nullable', 'string', 'max:100'],
            'recepcion.fecha_etiqueta' => ['nullable', 'date'],

            'partner_recepcion_id' => [
                'nullable', 'integer',
                Rule::exists('partners', 'id')->where(
                    fn ($query) => $query->where('tipo_socio', 'logistico')->where('activo', true),
                ),
            ],
            'partner_logistico' => ['nullable', 'string', 'max:255'],

            'external_id' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $resultado = $accion->ejecutar($ordenServicio, $data, $data['external_id'] ?? null);
        } catch (OrdenBloqueadaException $exception) {
            Log::warning('API interna: corrección de perfil rechazada en orden cerrada.', [
                'orden_id' => $ordenServicio->id,
                'folio' => $ordenServicio->folio,
            ]);

            return response()->json([
                'message' => $exception->getMessage(),
                'folio' => $ordenServicio->folio,
                'estado' => $ordenServicio->estado,
            ], 409);
        }

        $ordenActual = $resultado['orden'];

        Log::info('API interna: perfil de orden corregido desde OpenClaw.', [
            'orden_id' => $ordenActual->id,
            'folio' => $ordenActual->folio,
            'aplicado' => $resultado['aplicado'],
            'duplicado' => $resultado['duplicado'],
            'cambios' => $resultado['cambios'],
            // Sin valores sensibles: cambios solo nombra campos, nunca password.
        ]);

        return response()->json([
            'aplicado' => $resultado['aplicado'],
            'duplicado' => $resultado['duplicado'],
            'id' => $ordenActual->id,
            'folio' => $ordenActual->folio,
            'estado' => $ordenActual->estado,
            'cliente' => [
                'id' => $ordenActual->cliente?->id,
                'nombre' => $ordenActual->cliente?->nombre,
                'telefono' => $ordenActual->cliente?->telefono,
            ],
            'equipo' => $ordenActual->equipo ? [
                'id' => $ordenActual->equipo->id,
                'tipo_equipo' => $ordenActual->equipo->tipo_equipo,
                'marca' => $ordenActual->equipo->marca,
                'modelo' => $ordenActual->equipo->modelo,
                'numero_serie' => $ordenActual->equipo->numero_serie,
                'password_registrada' => filled($ordenActual->equipo->password_equipo),
            ] : null,
            'partner_recepcion' => $ordenActual->partnerRecepcion?->nombre,
            'cambios_aplicados' => $resultado['cambios'],
            'warnings' => $resultado['warnings'],
            'show_url' => route('admin.ordenes-servicio.show', $ordenActual),
        ]);
    }

    /**
     * Apply changes (add services / parts / notes) to an existing service order
     * from the internal API (OpenClaw). The order is identified by numeric id or
     * by folio (e.g. OS-20260705-0004).
     */
    public function changes(Request $request, string $orden, AplicarCambiosOrdenDesdeOpenClaw $accion): JsonResponse
    {
        $ordenServicio = $this->resolverOrden($orden);
        abort_if($ordenServicio === null, 404, 'Orden de servicio no encontrada.');

        // Tolerancia de contrato: también se acepta el wrapper `agregar` con las
        // mismas listas dentro ({"agregar": {"servicios": [...], "refacciones": [...]}}).
        // El formato recomendado sigue siendo con las listas en la raíz; si ambas
        // formas vienen, gana la raíz.
        $agregar = $request->input('agregar');
        if (is_array($agregar)) {
            foreach (['servicios', 'refacciones', 'servicios_sugeridos'] as $clave) {
                if (! $request->has($clave) && array_key_exists($clave, $agregar)) {
                    $request->merge([$clave => $agregar[$clave]]);
                }
            }
        }

        $data = $request->validate([
            'external_id' => ['nullable', 'string', 'max:255'],

            'servicios' => ['nullable', 'array'],
            'servicios.*.servicio_id' => ['nullable', 'integer', 'exists:servicios,id'],
            'servicios.*.descripcion' => ['nullable', 'string', 'max:255'],
            'servicios.*.precio' => ['nullable', 'numeric', 'min:0'],
            'servicios.*.precio_cliente' => ['nullable', 'numeric', 'min:0'],
            'servicios.*.cantidad' => ['nullable', 'integer', 'min:1'],
            'servicios.*.notas' => ['nullable', 'string', 'max:1000'],

            'servicios_sugeridos' => ['nullable', 'array'],
            'servicios_sugeridos.*.descripcion' => ['required', 'string', 'max:255'],
            'servicios_sugeridos.*.precio' => ['nullable', 'numeric', 'min:0'],

            'refacciones' => ['nullable', 'array'],
            'refacciones.*.descripcion' => ['required', 'string', 'max:255'],
            'refacciones.*.precio' => ['nullable', 'numeric', 'min:0'],
            'refacciones.*.precio_cliente' => ['nullable', 'numeric', 'min:0'],
            'refacciones.*.costo_unitario' => ['nullable', 'numeric', 'min:0'],
            'refacciones.*.cantidad' => ['nullable', 'integer', 'min:1'],
            'refacciones.*.notas' => ['nullable', 'string', 'max:1000'],

            'notas' => ['nullable', 'string', 'max:3000'],
        ]);

        try {
            $resultado = $accion->ejecutar($ordenServicio, $data, $data['external_id'] ?? null);
        } catch (OrdenBloqueadaException $exception) {
            Log::warning('API interna: cambio rechazado en orden con finanzas cerradas.', [
                'orden_id' => $ordenServicio->id,
                'folio' => $ordenServicio->folio,
            ]);

            return response()->json([
                'message' => $exception->getMessage(),
                'folio' => $ordenServicio->folio,
                'estado' => $ordenServicio->estado,
            ], 409);
        }

        $orden = $resultado['orden'];

        Log::info('API interna: cambios aplicados a orden desde OpenClaw.', [
            'orden_id' => $orden->id,
            'folio' => $orden->folio,
            'aplicado' => $resultado['aplicado'],
            'duplicado' => $resultado['duplicado'],
            'servicios_agregados' => count($resultado['servicios_agregados']),
            'refacciones_agregadas' => count($resultado['refacciones_agregadas']),
        ]);

        return response()->json([
            'aplicado' => $resultado['aplicado'],
            'duplicado' => $resultado['duplicado'],
            'id' => $orden->id,
            'folio' => $orden->folio,
            'cliente' => $orden->cliente?->nombre,
            'estado' => $orden->estado,
            'total_cliente' => (float) $orden->total_cliente,
            'servicios_agregados' => $resultado['servicios_agregados'],
            'refacciones_agregadas' => $resultado['refacciones_agregadas'],
            'warnings' => $resultado['warnings'],
            'show_url' => route('admin.ordenes-servicio.show', $orden),
        ]);
    }

    /**
     * Change a service order status from the internal API (OpenClaw). Mirrors the
     * panel's flow; `entregado` requires confirm_final_delivery=true because it
     * closes the order and generates finances.
     */
    public function status(Request $request, string $orden, CambiarEstadoOrdenDesdeOpenClaw $accion): JsonResponse
    {
        $ordenServicio = $this->resolverOrden($orden);
        abort_if($ordenServicio === null, 404, 'Orden de servicio no encontrada.');

        $data = $request->validate([
            'estado' => ['required', Rule::in(OrdenServicio::ESTADOS)],
            'notas' => ['nullable', 'string', 'max:1000'],
            'external_id' => ['nullable', 'string', 'max:255'],
            'confirm_final_delivery' => ['nullable', 'boolean'],
        ]);

        try {
            $resultado = $accion->ejecutar(
                $ordenServicio,
                $data['estado'],
                $data['notas'] ?? null,
                $data['external_id'] ?? null,
                (bool) ($data['confirm_final_delivery'] ?? false),
            );
        } catch (OrdenBloqueadaException|ConfirmacionEntregaRequeridaException $exception) {
            Log::warning('API interna: cambio de estado rechazado.', [
                'orden_id' => $ordenServicio->id,
                'folio' => $ordenServicio->folio,
                'estado_solicitado' => $data['estado'],
                'motivo' => $exception::class,
            ]);

            return response()->json([
                'message' => $exception->getMessage(),
                'folio' => $ordenServicio->folio,
                'estado' => $ordenServicio->estado,
                'requires_confirmation' => $exception instanceof ConfirmacionEntregaRequeridaException,
            ], 409);
        }

        $ordenActual = $resultado['orden'];
        $ordenActual->loadMissing(['cliente', 'equipo']);

        Log::info('API interna: cambio de estado procesado desde OpenClaw.', [
            'orden_id' => $ordenActual->id,
            'folio' => $ordenActual->folio,
            'estado_anterior' => $resultado['estado_anterior'],
            'estado_actual' => $ordenActual->estado,
            'cambiado' => $resultado['cambiado'],
            'duplicado' => $resultado['duplicado'],
            'finanzas_generadas' => $ordenActual->finanzas_generadas,
        ]);

        return response()->json([
            'cambiado' => $resultado['cambiado'],
            'duplicado' => $resultado['duplicado'],
            'id' => $ordenActual->id,
            'folio' => $ordenActual->folio,
            'estado_anterior' => $resultado['estado_anterior'],
            'estado_actual' => $ordenActual->estado,
            'cliente' => $ordenActual->cliente?->nombre,
            'equipo' => $ordenActual->equipo ? [
                'tipo_equipo' => $ordenActual->equipo->tipo_equipo,
                'marca' => $ordenActual->equipo->marca,
                'modelo' => $ordenActual->equipo->modelo,
                // Nunca password_equipo.
            ] : null,
            'total_cliente' => (float) $ordenActual->total_cliente,
            'finanzas_generadas' => (bool) $ordenActual->finanzas_generadas,
            'fecha_entrega' => $ordenActual->fecha_entrega?->toIso8601String(),
            'show_url' => route('admin.ordenes-servicio.show', $ordenActual),
            'warnings' => $resultado['warnings'],
        ]);
    }

    /**
     * Resolve an order by numeric id or by folio (OS-YYYYMMDD-0001).
     */
    private function resolverOrden(string $orden): ?OrdenServicio
    {
        return ctype_digit($orden)
            ? OrdenServicio::query()->find((int) $orden)
            : OrdenServicio::query()->where('folio', $orden)->first();
    }

    /**
     * Safe JSON payload of an order for OpenClaw. Loads nothing by itself: the
     * caller eager-loads. Never includes password_equipo.
     *
     * @return array<string, mixed>
     */
    private function payloadOrden(OrdenServicio $orden): array
    {
        return [
            'id' => $orden->id,
            'folio' => $orden->folio,
            'external_id' => $orden->external_id,
            'origen' => $orden->origen,
            'cliente' => $orden->cliente ? [
                'id' => $orden->cliente->id,
                'nombre' => $orden->cliente->nombre,
                'telefono' => $orden->cliente->telefono,
            ] : null,
            'equipo' => $orden->equipo ? [
                'id' => $orden->equipo->id,
                'tipo' => $orden->equipo->tipo_equipo,
                'marca' => $orden->equipo->marca,
                'modelo' => $orden->equipo->modelo,
                'numero_serie' => $orden->equipo->numero_serie,
                'password_registrada' => filled($orden->equipo->password_equipo),
            ] : null,
            'estado' => $orden->estado,
            'estado_label' => $orden->estadoLabel(),
            'partner_recepcion' => $orden->partnerRecepcion?->nombre,
            'partner_tecnico' => $orden->partnerTecnico?->nombre,
            'tipo_recepcion' => $orden->tipo_recepcion,
            'falla_reportada' => $this->extraerFallaReportada($orden->notas),
            'notas' => $orden->notas,
            'servicios' => $orden->detalles->map(fn ($detalle): array => [
                'servicio_id' => $detalle->servicio_id,
                'nombre' => $detalle->servicio?->nombre,
                'cantidad' => (int) $detalle->cantidad,
                'precio_unitario' => (float) $detalle->precio_unitario,
                'subtotal' => (float) $detalle->subtotal,
                'notas' => $detalle->notas,
            ])->values()->all(),
            'refacciones' => $orden->refacciones->map(fn ($refaccion): array => [
                'descripcion' => $refaccion->descripcion,
                'cantidad' => (int) $refaccion->cantidad,
                'costo_unitario' => (float) $refaccion->costo_unitario,
                'precio_unitario_cliente' => (float) $refaccion->precio_unitario_cliente,
                'precio_total_cliente' => (float) $refaccion->precio_total_cliente,
                'notas' => $refaccion->notas,
            ])->values()->all(),
            'total_cliente' => (float) $orden->total_cliente,
            'costo_tecnico' => (float) $orden->costo_tecnico,
            'finanzas_generadas' => (bool) $orden->finanzas_generadas,
            'fecha_recepcion' => $orden->fecha_recepcion?->toIso8601String(),
            'fecha_entrega' => $orden->fecha_entrega?->toIso8601String(),
            'show_url' => route('admin.ordenes-servicio.show', $orden),
        ];
    }

    /**
     * Best-effort extraction of the reported fault from the order notes: the
     * order has no dedicated column, so the reception flow stores it as a
     * "Falla reportada:" block. A later correction ("Falla reportada
     * (corregida): ...") wins over the original.
     */
    private function extraerFallaReportada(?string $notas): ?string
    {
        if (blank($notas)) {
            return null;
        }

        if (preg_match_all('/Falla reportada \(corregida\):\s*(.+)/u', $notas, $corregidas) && $corregidas[1] !== []) {
            return trim((string) end($corregidas[1]));
        }

        if (preg_match('/Falla reportada:\s*\n?(.+?)(?:\n\n|$)/su', $notas, $original)) {
            $falla = trim($original[1]);

            return $falla === '(no especificada en la etiqueta)' ? null : $falla;
        }

        return null;
    }
}
