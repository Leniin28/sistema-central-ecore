<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRecepcionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() || $this->user()?->hasRole('socio_logistico');
    }

    protected function prepareForValidation(): void
    {
        $orden = $this->input('orden', []);
        if (is_array($orden)) {
            if (array_key_exists('nota_recepcion', $orden)) {
                if (is_string($orden['nota_recepcion'])) {
                    $notaRecepcion = trim($orden['nota_recepcion']);
                    $orden['nota_recepcion'] = $notaRecepcion !== '' ? $notaRecepcion : null;
                }
            }

            if ($this->user()?->isAdmin()
                && array_key_exists('comision_recepcion', $orden)
                && $orden['comision_recepcion'] === '') {
                $orden['comision_recepcion'] = null;
            }
        }

        $esAdmin = (bool) $this->user()?->isAdmin();
        $normalizarCosto = function (array $row) use ($esAdmin): array {
            if ($esAdmin && array_key_exists('costo_unitario', $row) && $row['costo_unitario'] === '') {
                $row['costo_unitario'] = null;
            }

            return $row;
        };

        $servicios = collect($this->input('servicios', []))
            ->filter(fn ($row): bool => is_array($row) && filled($row['servicio_id'] ?? null))
            ->map($normalizarCosto)
            ->values()
            ->all();
        $refacciones = collect($this->input('refacciones', []))
            ->filter(fn ($row): bool => is_array($row) && (
                filled($row['descripcion'] ?? null)
                || filled($row['costo_unitario'] ?? null)
                || filled($row['precio_unitario_cliente'] ?? null)
                || filled($row['notas'] ?? null)
            ))
            ->map($normalizarCosto)
            ->values()
            ->all();

        $this->merge(compact('orden', 'servicios', 'refacciones'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'cliente_modo' => ['required', Rule::in(['existente', 'nuevo'])],
            'cliente_id' => ['required_if:cliente_modo,existente', 'nullable', 'exists:clientes,id'],
            'cliente.nombre' => ['required_if:cliente_modo,nuevo', 'nullable', 'string', 'max:255'],
            'cliente.telefono' => ['required_if:cliente_modo,nuevo', 'nullable', 'string', 'max:50'],
            'cliente.correo' => ['nullable', 'email', 'max:255'],
            'cliente.tipo_cliente' => ['required_if:cliente_modo,nuevo', 'nullable', Rule::in(['mantenimiento', 'marketing', 'ambos'])],
            'cliente.notas' => ['nullable', 'string'],

            'equipo_modo' => ['required', Rule::in(['existente', 'nuevo'])],
            'equipo_id' => ['required_if:equipo_modo,existente', 'nullable', 'exists:equipos,id'],
            'equipo.tipo_equipo' => ['required_if:equipo_modo,nuevo', 'nullable', 'string', 'max:100'],
            'equipo.marca' => ['required_if:equipo_modo,nuevo', 'nullable', 'string', 'max:100'],
            'equipo.modelo' => ['nullable', 'string', 'max:100'],
            'equipo.numero_serie' => ['nullable', 'string', 'max:150'],
            'equipo.password_equipo' => ['nullable', 'string', 'max:150'],
            'equipo.estado_fisico_inicial' => ['nullable', 'string'],
            'equipo.accesorios_recibidos' => ['nullable', 'string'],

            'orden.tipo_recepcion' => ['required', Rule::in(['sucursal', 'domicilio', 'directo'])],
            'orden.partner_recepcion_id' => ['nullable', Rule::exists('partners', 'id')->where(fn ($query) => $query->where('tipo_socio', 'logistico')->where('activo', true))],
            'orden.partner_tecnico_id' => ['nullable', Rule::exists('partners', 'id')->where(fn ($query) => $query->where('tipo_socio', 'tecnico')->where('activo', true))],
            'orden.comision_recepcion' => $this->user()?->isAdmin()
                ? ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:99999999.99']
                : ['prohibited'],
            'orden.nota_recepcion' => ['nullable', 'string', 'max:255'],
            'orden.problema_reportado' => ['required', 'string', 'max:3000'],
            'orden.notas' => ['nullable', 'string', 'max:3000'],

            'servicios' => ['array'],
            'servicios.*.servicio_id' => ['required', 'exists:servicios,id'],
            'servicios.*.cantidad' => ['required', 'integer', 'min:1'],
            'servicios.*.precio_unitario' => ['required', 'numeric', 'min:0'],
            'servicios.*.costo_unitario' => $this->user()?->isAdmin()
                ? ['nullable', 'numeric', 'min:0']
                : ['prohibited'],
            'servicios.*.notas' => ['nullable', 'string', 'max:1000'],

            'refacciones' => ['array'],
            'refacciones.*.descripcion' => ['required', 'string', 'max:255'],
            'refacciones.*.cantidad' => ['required', 'integer', 'min:1'],
            'refacciones.*.costo_unitario' => $this->user()?->isAdmin()
                ? ['nullable', 'numeric', 'min:0']
                : ['prohibited'],
            'refacciones.*.precio_unitario_cliente' => ['required', 'numeric', 'min:0'],
            'refacciones.*.notas' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
