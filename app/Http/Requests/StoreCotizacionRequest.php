<?php

namespace App\Http\Requests;

use App\Models\Cotizacion;
use App\Models\CotizacionItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCotizacionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() || $this->user()?->hasRole('socio_logistico');
    }

    protected function prepareForValidation(): void
    {
        $items = collect($this->input('items', []))
            ->filter(fn ($row): bool => is_array($row) && (
                filled($row['descripcion'] ?? null)
                || filled($row['cantidad'] ?? null)
                || filled($row['precio_unitario'] ?? null)
            ))
            ->values()
            ->all();

        $this->merge(compact('items'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'cliente_id' => ['required', 'exists:clientes,id'],
            'equipo_id' => ['nullable', 'exists:equipos,id'],
            'fecha' => ['required', 'date'],
            'vigencia' => ['nullable', 'date', 'after_or_equal:fecha'],
            'tipo_recepcion' => ['nullable', Rule::in(Cotizacion::TIPOS_RECEPCION)],
            'direccion_recepcion' => [
                'nullable',
                'string',
                'max:500',
                'required_if:tipo_recepcion,recogido_a_domicilio',
            ],
            'descuento' => ['nullable', 'numeric', 'min:0'],
            'anticipo' => ['nullable', 'numeric', 'min:0'],
            'notas' => ['nullable', 'string', 'max:3000'],

            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['nullable', 'integer'],
            'items.*.tipo' => ['required', Rule::in(CotizacionItem::TIPOS)],
            'items.*.servicio_id' => ['nullable', 'exists:servicios,id'],
            'items.*.descripcion' => ['required', 'string', 'max:255'],
            'items.*.cantidad' => ['required', 'integer', 'min:1'],
            'items.*.precio_unitario' => ['required', 'numeric', 'min:0'],
            'items.*.costo_unitario' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'direccion_recepcion.required_if' => 'Captura la dirección del cliente cuando el equipo se recoge a domicilio.',
        ];
    }
}
