<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'orden_servicio_id',
    'cotizacion_item_id',
    'descripcion',
    'cantidad',
    'costo_unitario',
    'precio_unitario_cliente',
    'costo_total',
    'precio_total_cliente',
    'utilidad_refaccion',
    'notas',
])]
class OrdenServicioRefaccion extends Model
{
    protected $table = 'orden_servicio_refacciones';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cantidad' => 'integer',
            'costo_unitario' => 'decimal:2',
            'precio_unitario_cliente' => 'decimal:2',
            'costo_total' => 'decimal:2',
            'precio_total_cliente' => 'decimal:2',
            'utilidad_refaccion' => 'decimal:2',
        ];
    }

    /**
     * Get the service order that owns the part.
     */
    public function ordenServicio(): BelongsTo
    {
        return $this->belongsTo(OrdenServicio::class);
    }
}
