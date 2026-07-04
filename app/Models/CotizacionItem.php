<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'cotizacion_id',
    'tipo',
    'descripcion',
    'cantidad',
    'precio_unitario',
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
}
