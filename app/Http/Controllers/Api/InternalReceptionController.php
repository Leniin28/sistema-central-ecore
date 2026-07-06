<?php

namespace App\Http\Controllers\Api;

use App\Actions\Recepciones\RegistrarRecepcionDesdeOpenClaw;
use App\Http\Controllers\Controller;
use App\Models\OrdenServicio;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class InternalReceptionController extends Controller
{
    /**
     * Register a reception / service order from the internal API (OpenClaw),
     * from data extracted out of a photo of a physical label.
     */
    public function store(Request $request, RegistrarRecepcionDesdeOpenClaw $accion): JsonResponse
    {
        $data = $request->validate([
            // Cliente: id existente O alta rápida con nombre.
            'cliente_id' => ['nullable', 'required_without:cliente', 'exists:clientes,id'],
            'cliente' => ['nullable', 'required_without:cliente_id', 'array'],
            'cliente.nombre' => ['required_with:cliente', 'string', 'max:255'],
            'cliente.telefono' => ['nullable', 'string', 'max:50'],
            'cliente.correo' => ['nullable', 'email', 'max:255'],
            'cliente.tipo_cliente' => ['nullable', Rule::in(['mantenimiento', 'marketing', 'ambos'])],

            // Equipo: se exige tipo_equipo O modelo (uno identifica el equipo).
            'equipo' => ['required', 'array'],
            'equipo.tipo_equipo' => ['required_without:equipo.modelo', 'nullable', 'string', 'max:100'],
            'equipo.modelo' => ['required_without:equipo.tipo_equipo', 'nullable', 'string', 'max:100'],
            'equipo.marca' => ['nullable', 'string', 'max:100'],
            'equipo.numero_serie' => ['nullable', 'string', 'max:150'],
            'equipo.password_equipo' => ['nullable', 'string', 'max:150'],

            'recepcion' => ['nullable', 'array'],
            'recepcion.falla_reportada' => ['nullable', 'string', 'max:3000'],
            'recepcion.fecha_etiqueta' => ['nullable', 'date'],
            'recepcion.folio_externo' => ['nullable', 'string', 'max:100'],
            'recepcion.origen' => ['nullable', 'string', 'max:100'],
            'recepcion.notas' => ['nullable', 'string', 'max:3000'],

            // Sucursal / partner logístico detectado en la etiqueta. Id explícito
            // (validado como logístico activo) o nombre a resolver de forma tolerante.
            'partner_recepcion_id' => ['nullable', 'integer', $this->reglaPartnerLogistico()],
            'partner_logistico' => ['nullable', 'string', 'max:255'],
            'recepcion.partner_recepcion_id' => ['nullable', 'integer', $this->reglaPartnerLogistico()],
            'recepcion.partner_logistico' => ['nullable', 'string', 'max:255'],
            'recepcion.partner_logistico_nombre' => ['nullable', 'string', 'max:255'],
            'recepcion.sucursal_nombre' => ['nullable', 'string', 'max:255'],

            'tipo_recepcion' => ['nullable', Rule::in(['sucursal', 'domicilio', 'directo'])],

            // Servicios: se agregan como líneas reales si se resuelven contra el catálogo
            // (servicio_id explícito o match por nombre); si no, quedan como warning.
            'servicios' => ['nullable', 'array'],
            'servicios.*.servicio_id' => ['nullable', 'integer', 'exists:servicios,id'],
            'servicios.*.descripcion' => ['nullable', 'string', 'max:255'],
            'servicios.*.precio' => ['nullable', 'numeric', 'min:0'],
            'servicios.*.precio_cliente' => ['nullable', 'numeric', 'min:0'],
            'servicios.*.cantidad' => ['nullable', 'integer', 'min:1'],
            'servicios.*.notas' => ['nullable', 'string', 'max:1000'],

            // Refacciones: texto libre, se agregan como líneas reales.
            'refacciones' => ['nullable', 'array'],
            'refacciones.*.descripcion' => ['required', 'string', 'max:255'],
            'refacciones.*.precio' => ['nullable', 'numeric', 'min:0'],
            'refacciones.*.precio_cliente' => ['nullable', 'numeric', 'min:0'],
            'refacciones.*.costo_unitario' => ['nullable', 'numeric', 'min:0'],
            'refacciones.*.cantidad' => ['nullable', 'integer', 'min:1'],
            'refacciones.*.notas' => ['nullable', 'string', 'max:1000'],

            'notas' => ['nullable', 'string', 'max:3000'],
            'external_id' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $resultado = $accion->ejecutar($data);
        } catch (\Throwable $exception) {
            // Nunca registrar password_equipo ni el payload completo en claro.
            Log::warning('API interna: fallo al registrar recepción de OpenClaw.', [
                'error' => $exception->getMessage(),
                'external_id' => $data['external_id'] ?? null,
            ]);

            throw $exception;
        }

        /** @var OrdenServicio $orden */
        $orden = $resultado['orden'];
        $orden->loadMissing(['cliente', 'equipo', 'partnerRecepcion']);

        Log::info('API interna: recepción de OpenClaw procesada.', [
            'orden_id' => $orden->id,
            'folio' => $orden->folio,
            'created' => $resultado['created'],
            'external_id' => $orden->external_id,
            // Sin password_equipo: dato sensible, no se registra.
        ]);

        return response()->json([
            'created' => $resultado['created'],
            'id' => $orden->id,
            'folio' => $orden->folio,
            'estado' => $orden->estado,
            'external_id' => $orden->external_id,
            'origen' => $orden->origen,
            'cliente' => [
                'id' => $orden->cliente?->id,
                'nombre' => $orden->cliente?->nombre,
            ],
            'equipo' => [
                'id' => $orden->equipo?->id,
                'tipo_equipo' => $orden->equipo?->tipo_equipo,
                'marca' => $orden->equipo?->marca,
                'modelo' => $orden->equipo?->modelo,
                // Solo se confirma que quedó registrada; NUNCA se devuelve el valor.
                'password_equipo_registrada' => filled($orden->equipo?->password_equipo),
            ],
            'partner_recepcion' => $orden->partnerRecepcion ? [
                'id' => $orden->partnerRecepcion->id,
                'nombre' => $orden->partnerRecepcion->nombre,
            ] : null,
            'total_cliente' => (float) $orden->total_cliente,
            'servicios_agregados' => $resultado['servicios_agregados'],
            'refacciones_agregadas' => $resultado['refacciones_agregadas'],
            'show_url' => route('admin.ordenes-servicio.show', $orden),
            'mensaje_resumen' => $this->mensajeResumen($orden, $resultado['created']),
            'warnings' => $resultado['warnings'],
        ], $resultado['created'] ? 201 : 200);
    }

    /**
     * Rule that a partner id must reference an active logistics partner
     * (same constraint the web reception form enforces).
     */
    private function reglaPartnerLogistico(): \Illuminate\Validation\Rules\Exists
    {
        return Rule::exists('partners', 'id')->where(
            fn ($query) => $query->where('tipo_socio', 'logistico')->where('activo', true),
        );
    }

    private function mensajeResumen(OrdenServicio $orden, bool $created): string
    {
        $prefijo = $created ? 'Recepción registrada' : 'Recepción ya existente (reenvío)';

        return "{$prefijo}: orden {$orden->folio} para {$orden->cliente?->nombre}"
            .($orden->equipo?->modelo ? " ({$orden->equipo->modelo})" : '').'.';
    }
}
