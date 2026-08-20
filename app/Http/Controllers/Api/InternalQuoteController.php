<?php

namespace App\Http\Controllers\Api;

use App\Actions\Cotizaciones\ConvertirCotizacionEnOrden;
use App\Actions\Cotizaciones\CrearCotizacion;
use App\Http\Controllers\Controller;
use App\Models\Cotizacion;
use App\Models\CotizacionItem;
use App\Models\OrdenServicio;
use App\Services\ExportarCotizacionPdf;
use App\Services\ExportarCotizacionPng;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class InternalQuoteController extends Controller
{
    /**
     * Create a quote from the internal API (OpenClaw).
     */
    public function store(Request $request, CrearCotizacion $accion): JsonResponse
    {
        $data = $request->validate([
            'cliente_id' => ['nullable', 'required_without:cliente', 'exists:clientes,id'],
            'cliente' => ['nullable', 'required_without:cliente_id', 'array'],
            'cliente.nombre' => ['required_with:cliente', 'string', 'max:255'],
            'cliente.telefono' => ['nullable', 'string', 'max:50'],
            'cliente.direccion' => ['nullable', 'string', 'max:500'],

            'equipo_id' => ['nullable', 'exists:equipos,id'],
            'equipo' => ['nullable', 'array'],
            'equipo.tipo_equipo' => ['required_with:equipo', 'string', 'max:100'],
            'equipo.marca' => ['nullable', 'string', 'max:100'],
            'equipo.modelo' => ['nullable', 'string', 'max:100'],
            'equipo.numero_serie' => ['nullable', 'string', 'max:150'],

            'items' => ['required', 'array', 'min:1'],
            'items.*.tipo' => ['required', Rule::in(CotizacionItem::TIPOS)],
            'items.*.descripcion' => ['required', 'string', 'max:255'],
            'items.*.cantidad' => ['required', 'integer', 'min:1'],
            'items.*.precio_unitario' => ['required', 'numeric', 'min:0'],
            'items.*.costo_unitario' => ['nullable', 'numeric', 'min:0'],

            'descuento' => ['nullable', 'numeric', 'min:0'],
            'anticipo' => ['nullable', 'numeric', 'min:0'],
            'notas' => ['nullable', 'string', 'max:3000'],
            'vigencia' => ['nullable', 'date'],
            'external_id' => ['nullable', 'string', 'max:255'],

            'tipo_recepcion' => ['nullable', Rule::in(Cotizacion::TIPOS_RECEPCION)],
            'direccion_recepcion' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $cotizacion = $accion->ejecutar($data, $data['items'], null);
        } catch (\Throwable $exception) {
            Log::warning('API interna: fallo al crear cotización.', [
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }

        $cotizacion->loadMissing(['cliente', 'equipo']);

        Log::info('API interna: cotización creada.', [
            'cotizacion_id' => $cotizacion->id,
            'folio' => $cotizacion->folio,
        ]);

        return response()->json([
            'id' => $cotizacion->id,
            'folio' => $cotizacion->folio,
            'external_id' => $cotizacion->external_id,
            'estado' => $cotizacion->estado,
            'subtotal' => (float) $cotizacion->subtotal,
            'descuento' => (float) $cotizacion->descuento,
            'anticipo' => (float) $cotizacion->anticipo,
            'total' => (float) $cotizacion->total,
            'saldo' => (float) $cotizacion->saldo,
            'cliente' => [
                'id' => $cotizacion->cliente?->id,
                'nombre' => $cotizacion->cliente?->nombre,
            ],
            'equipo' => $cotizacion->equipo ? [
                'id' => $cotizacion->equipo->id,
                'tipo_equipo' => $cotizacion->equipo->tipo_equipo,
                'marca' => $cotizacion->equipo->marca,
                'modelo' => $cotizacion->equipo->modelo,
            ] : null,
            'tipo_recepcion' => $cotizacion->tipo_recepcion,
            'direccion_recepcion' => $cotizacion->direccion_recepcion,
            // URLs internas que OpenClaw debe usar: descargan PDF/PNG con el mismo Bearer Token, sin sesión web.
            'internal_pdf_url' => route('api.internal.quotes.pdf', $cotizacion),
            'internal_png_url' => route('api.internal.quotes.png', $cotizacion),
            'pdf_download_endpoint' => route('api.internal.quotes.pdf', $cotizacion, false),
            'png_download_endpoint' => route('api.internal.quotes.png', $cotizacion, false),
            // Rutas del panel web: solo funcionan para usuarios autenticados con sesión (no para OpenClaw).
            'pdf_url' => route('admin.cotizaciones.pdf', $cotizacion),
            'show_url' => route('admin.cotizaciones.show', $cotizacion),
            'web_urls_require_session' => true,
        ], 201);
    }

    /**
     * List quotes still waiting for a client answer (borrador/enviada), for
     * OpenClaw follow-ups: "cotizaciones pendientes", "cuáles no han contestado".
     */
    public function pending(Request $request): JsonResponse
    {
        $filtros = $request->validate([
            'older_than_days' => ['nullable', 'integer', 'min:0'],
            'cliente' => ['nullable', 'string', 'max:255'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $limite = (int) ($filtros['limit'] ?? 20);
        $warnings = [];

        $pendientes = Cotizacion::query()
            ->whereIn('estado', ['borrador', 'enviada'])
            ->when($filtros['cliente'] ?? null, function ($query, string $cliente): void {
                $query->whereHas('cliente', function ($sub) use ($cliente): void {
                    $sub->where('nombre', 'like', "%{$cliente}%")->orWhere('telefono', 'like', "%{$cliente}%");
                });
            })
            ->with('cliente')
            ->orderBy('fecha')
            ->get()
            ->filter(function (Cotizacion $cotizacion) use ($filtros): bool {
                if (! isset($filtros['older_than_days'])) {
                    return true;
                }
                $fecha = $cotizacion->fecha ?? $cotizacion->created_at;

                return $fecha !== null
                    && (int) $fecha->copy()->startOfDay()->diffInDays(today(), false) >= (int) $filtros['older_than_days'];
            })
            ->values();

        if ($pendientes->count() > $limite) {
            $warnings[] = "Hay {$pendientes->count()} cotizaciones pendientes; se devolvieron las {$limite} más antiguas.";
        }

        return response()->json([
            'items' => $pendientes->take($limite)->map(function (Cotizacion $cotizacion): array {
                $fecha = $cotizacion->fecha ?? $cotizacion->created_at;

                return [
                    'id' => $cotizacion->id,
                    'folio' => $cotizacion->folio,
                    'estado' => $cotizacion->estado,
                    'cliente' => $cotizacion->cliente?->nombre,
                    'telefono' => $cotizacion->cliente?->telefono,
                    'total' => (float) $cotizacion->total,
                    'saldo' => (float) $cotizacion->saldo,
                    'dias_pendiente' => max(0, (int) ($fecha?->copy()->startOfDay()->diffInDays(today(), false) ?? 0)),
                    'vigencia' => $cotizacion->vigencia?->toDateString(),
                    'show_url' => route('admin.cotizaciones.show', $cotizacion),
                    'pdf_url' => route('api.internal.quotes.pdf', $cotizacion),
                    'png_url' => route('api.internal.quotes.png', $cotizacion),
                ];
            })->values()->all(),
            'warnings' => $warnings,
        ]);
    }

    /**
     * Convert an accepted quote into a service order (OpenClaw). Hardened: an
     * empty or incomplete POST must never create an order nor touch the quote.
     * All checks below are read-only and run BEFORE the action's transaction.
     * Idempotent by external_id; unresolved service items become
     * warnings/notes, never fake catalog lines.
     */
    public function convertToOrder(Request $request, Cotizacion $cotizacion, ConvertirCotizacionEnOrden $accion): JsonResponse
    {
        $data = $request->validate([
            // Obligatorio: sin external_id no hay idempotencia y un reintento
            // de Telegram duplicaría la orden. También corta el body vacío.
            'external_id' => ['required', 'string', 'max:255'],
            'partner_recepcion_id' => [
                'nullable', 'integer',
                Rule::exists('partners', 'id')->where(
                    fn ($query) => $query->where('tipo_socio', 'logistico')->where('activo', true),
                ),
            ],
            'partner_logistico' => ['nullable', 'string', 'max:255'],

            // Contexto mínimo de recepción: falla reportada o al menos notas.
            'recepcion' => ['required', 'array'],
            'recepcion.falla_reportada' => ['nullable', 'required_without:recepcion.notas', 'string', 'max:3000'],
            'recepcion.notas' => ['nullable', 'required_without:recepcion.falla_reportada', 'string', 'max:3000'],

            'equipo' => ['nullable', 'array'],
            'equipo.tipo_equipo' => ['nullable', 'string', 'max:100'],
            'equipo.marca' => ['nullable', 'string', 'max:100'],
            'equipo.modelo' => ['nullable', 'string', 'max:100'],
            'equipo.numero_serie' => ['nullable', 'string', 'max:150'],
            'equipo.password_equipo' => ['nullable', 'string', 'max:150'],
        ]);

        $cotizacion->asegurarOperativa();

        // Equipo mínimo: el de la cotización o uno identificable en el payload.
        if (! $cotizacion->equipo_id
            && blank($data['equipo']['tipo_equipo'] ?? null)
            && blank($data['equipo']['modelo'] ?? null)) {
            throw ValidationException::withMessages([
                'equipo.tipo_equipo' => 'La cotización no tiene equipo: envía equipo.tipo_equipo o equipo.modelo para poder crear la orden.',
            ]);
        }

        // Tras descartar una cotización absorbida, un reintento del mismo
        // external_id devuelve la orden ya creada aunque el estado haya cambiado.
        $existente = OrdenServicio::query()->where('external_id', $data['external_id'])->first();
        if ($existente) {
            return DB::transaction(function () use ($cotizacion, $existente): JsonResponse {
                $cotizacion = Cotizacion::query()->lockForUpdate()->findOrFail($cotizacion->id);
                $cotizacion->asegurarOperativa();

                return $this->respuestaConversion($cotizacion, $existente, created: false, warnings: []);
            });
        }

        // Solo se convierten cotizaciones vivas. Cualquier otro estado —incluido
        // un valor fuera de catálogo como "cancelada"— se rechaza sin efectos.
        if (! in_array($cotizacion->estado, ['borrador', 'enviada', 'aceptada'], true)) {
            Log::warning('API interna: conversión rechazada por estado de la cotización.', [
                'cotizacion_id' => $cotizacion->id,
                'folio' => $cotizacion->folio,
                'estado' => $cotizacion->estado,
            ]);

            return response()->json([
                'message' => $cotizacion->estado === 'cancelada'
                    ? "La cotización {$cotizacion->folio} está cancelada y no puede convertirse en orden."
                    : "La cotización {$cotizacion->folio} está \"{$cotizacion->estado}\" y no puede convertirse en orden.",
                'folio' => $cotizacion->folio,
                'estado' => $cotizacion->estado,
            ], 409);
        }

        // Ya convertida antes (aunque el external_id sea otro): no duplicar.
        $vinculada = $cotizacion->ordenServicio()->first();
        if ($vinculada) {
            $resultado = $accion->ejecutar($cotizacion, $data);

            return $this->respuestaConversion($resultado['cotizacion'], $resultado['orden'], created: false, warnings: [
                "La cotización {$cotizacion->folio} ya fue convertida en la orden {$vinculada->folio}; no se creó otra.",
            ]);
        }

        // Compatibility fallback for legacy rows only. New conversions persist
        // cotizacion_id and never use notes as their source of truth.
        $convertida = OrdenServicio::query()
            ->where('origen', 'openclaw-cotizacion')
            ->where('notas', 'like', "%la cotización {$cotizacion->folio}%")
            ->first();
        if ($convertida) {
            return $this->respuestaConversion($cotizacion, $convertida, created: false, warnings: [
                "La cotización {$cotizacion->folio} ya fue convertida en la orden {$convertida->folio}; no se creó otra.",
            ]);
        }

        $resultado = $accion->ejecutar($cotizacion, $data);

        Log::info('API interna: cotización convertida en orden.', [
            'cotizacion_id' => $resultado['cotizacion']->id,
            'orden_id' => $resultado['orden']->id,
            'folio' => $resultado['orden']->folio,
            'created' => $resultado['created'],
        ]);

        return $this->respuestaConversion(
            $resultado['cotizacion'],
            $resultado['orden'],
            created: $resultado['created'],
            warnings: $resultado['warnings'],
        );
    }

    /**
     * Shared response shape for a conversion (new order or idempotent replay).
     * Never includes password_equipo.
     *
     * @param  array<int, string>  $warnings
     */
    private function respuestaConversion(Cotizacion $cotizacion, OrdenServicio $orden, bool $created, array $warnings): JsonResponse
    {
        $orden->loadMissing(['cliente', 'equipo', 'partnerRecepcion']);

        return response()->json([
            'created' => $created,
            'id' => $orden->id,
            'folio' => $orden->folio,
            'estado' => $orden->estado,
            'external_id' => $orden->external_id,
            'cotizacion' => [
                'id' => $cotizacion->id,
                'folio' => $cotizacion->folio,
                'estado' => $cotizacion->estado,
            ],
            'cliente' => [
                'id' => $orden->cliente?->id,
                'nombre' => $orden->cliente?->nombre,
            ],
            'equipo' => $orden->equipo ? [
                'id' => $orden->equipo->id,
                'tipo_equipo' => $orden->equipo->tipo_equipo,
                'marca' => $orden->equipo->marca,
                'modelo' => $orden->equipo->modelo,
                'password_registrada' => filled($orden->equipo->password_equipo),
            ] : null,
            'partner_recepcion' => $orden->partnerRecepcion?->nombre,
            'total_cliente' => (float) $orden->total_cliente,
            'warnings' => $warnings,
            'show_url' => route('admin.ordenes-servicio.show', $orden),
        ], $created ? 201 : 200);
    }

    /**
     * Download the PDF of a quote from the internal API (OpenClaw), without a web session.
     */
    public function pdf(Cotizacion $cotizacion, ExportarCotizacionPdf $exportador): Response
    {
        Log::info('API interna: descarga de PDF de cotización.', [
            'cotizacion_id' => $cotizacion->id,
            'folio' => $cotizacion->folio,
        ]);

        return $exportador->generar($cotizacion)->download($exportador->nombreArchivo($cotizacion));
    }

    /**
     * Download the PNG of a quote from the internal API (OpenClaw), without a web session.
     */
    public function png(Cotizacion $cotizacion, ExportarCotizacionPng $exportador): Response|JsonResponse
    {
        try {
            $imagen = $exportador->generar($cotizacion);
        } catch (\RuntimeException $exception) {
            Log::warning('API interna: fallo al generar PNG de cotización.', [
                'cotizacion_id' => $cotizacion->id,
                'error' => $exception->getMessage(),
            ]);

            return response()->json(['message' => $exception->getMessage()], 503);
        }

        Log::info('API interna: descarga de PNG de cotización.', [
            'cotizacion_id' => $cotizacion->id,
            'folio' => $cotizacion->folio,
        ]);

        return response($imagen, 200, [
            'Content-Type' => 'image/png',
            'Content-Disposition' => 'attachment; filename="'.$exportador->nombreArchivo($cotizacion).'"',
        ]);
    }
}
