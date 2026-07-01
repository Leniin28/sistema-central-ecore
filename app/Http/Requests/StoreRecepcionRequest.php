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
        $servicios = collect($this->input('servicios', []))
            ->filter(fn ($row): bool => is_array($row) && filled($row['servicio_id'] ?? null))
            ->values()
            ->all();
        $refacciones = collect($this->input('refacciones', []))
            ->filter(fn ($row): bool => is_array($row) && (
                filled($row['descripcion'] ?? null)
                || filled($row['costo_unitario'] ?? null)
                || filled($row['precio_unitario_cliente'] ?? null)
                || filled($row['notas'] ?? null)
            ))
            ->values()
            ->all();

        $this->merge(compact('servicios', 'refacciones'));
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
            'orden.problema_reportado' => ['required', 'string', 'max:3000'],
            'orden.notas' => ['nullable', 'string', 'max:3000'],

            'servicios' => ['required', 'array', 'min:1'],
            'servicios.*.servicio_id' => ['required', 'exists:servicios,id'],
            'servicios.*.cantidad' => ['required', 'integer', 'min:1'],
            'servicios.*.precio_unitario' => ['required', 'numeric', 'min:0'],
            'servicios.*.notas' => ['nullable', 'string', 'max:1000'],

            'refacciones' => ['array'],
            'refacciones.*.descripcion' => ['required', 'string', 'max:255'],
            'refacciones.*.cantidad' => ['required', 'integer', 'min:1'],
            'refacciones.*.costo_unitario' => ['required', 'numeric', 'min:0'],
            'refacciones.*.precio_unitario_cliente' => ['required', 'numeric', 'min:0'],
            'refacciones.*.notas' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
