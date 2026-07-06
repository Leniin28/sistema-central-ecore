<?php

namespace App\Http\Controllers\Api;

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
}
