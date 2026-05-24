<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'orden_servicio_id',
    'servicio_id',
    'cantidad',
    'precio_unitario',
    'subtotal',
    'notas',
])]
class OrdenServicioDetalle extends Model
{
    protected $table = 'orden_servicio_detalles';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'precio_unitario' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'cantidad' => 'integer',
        ];
    }

    /**
     * Get the service order that owns the detail.
     */
    public function ordenServicio(): BelongsTo
    {
        return $this->belongsTo(OrdenServicio::class);
    }

    /**
     * Get the service assigned to the detail.
     */
    public function servicio(): BelongsTo
    {
        return $this->belongsTo(Servicio::class);
    }
}
