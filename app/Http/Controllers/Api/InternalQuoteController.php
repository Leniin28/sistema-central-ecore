<?php

namespace App\Http\Controllers\Api;

use App\Actions\Cotizaciones\CrearCotizacion;
use App\Http\Controllers\Controller;
use App\Models\Cotizacion;
use App\Models\CotizacionItem;
use App\Services\ExportarCotizacionPdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

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

            'descuento' => ['nullable', 'numeric', 'min:0'],
            'anticipo' => ['nullable', 'numeric', 'min:0'],
            'notas' => ['nullable', 'string', 'max:3000'],
            'vigencia' => ['nullable', 'date'],
            'external_id' => ['nullable', 'string', 'max:255'],
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
            // URL interna que OpenClaw debe usar: descarga el PDF con el mismo Bearer Token, sin sesión web.
            'internal_pdf_url' => route('api.internal.quotes.pdf', $cotizacion),
            'pdf_download_endpoint' => route('api.internal.quotes.pdf', $cotizacion, false),
            // Rutas del panel web: solo funcionan para usuarios autenticados con sesión (no para OpenClaw).
            'pdf_url' => route('admin.cotizaciones.pdf', $cotizacion),
            'show_url' => route('admin.cotizaciones.show', $cotizacion),
            'web_urls_require_session' => true,
        ], 201);
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
}
