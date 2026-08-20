<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'cotizacion_id',
    'cotizacion_origen_id',
    'servicio_id',
    'tipo',
    'descripcion',
    'cantidad',
    'precio_unitario',
    'costo_unitario',
    'costo_total',
    'subtotal',
])]
class CotizacionItem extends Model
{
    protected $table = 'cotizacion_items';

    /**
     * The item types allowed in a quote.
     *
     * @var list<string>
     */
    public const TIPOS = ['servicio', 'refaccion', 'producto', 'otro'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'precio_unitario' => 'decimal:2',
            'costo_unitario' => 'decimal:2',
            'costo_total' => 'decimal:2',
            'subtotal' => 'decimal:2',
        ];
    }

    /**
     * Get the quote that owns the item.
     */
    public function cotizacion(): BelongsTo
    {
        return $this->belongsTo(Cotizacion::class);
    }

    public function cotizacionOrigen(): BelongsTo
    {
        return $this->belongsTo(Cotizacion::class, 'cotizacion_origen_id');
    }

    public function servicio(): BelongsTo
    {
        return $this->belongsTo(Servicio::class);
    }
}
