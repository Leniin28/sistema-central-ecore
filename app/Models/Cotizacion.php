<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'folio',
    'cliente_id',
    'equipo_id',
    'creado_por_user_id',
    'partner_id',
    'fecha',
    'vigencia',
    'estado',
    'subtotal',
    'descuento',
    'anticipo',
    'total',
    'saldo',
    'notas',
    'external_id',
])]
class Cotizacion extends Model
{
    protected $table = 'cotizaciones';

    /**
     * The states a quote can be in.
     *
     * @var list<string>
     */
    public const ESTADOS = ['borrador', 'enviada', 'aceptada', 'rechazada', 'vencida'];

    /**
     * The states that still allow editing the quote.
     *
     * @var list<string>
     */
    public const ESTADOS_EDITABLES = ['borrador', 'enviada'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fecha' => 'date',
            'vigencia' => 'date',
            'subtotal' => 'decimal:2',
            'descuento' => 'decimal:2',
            'anticipo' => 'decimal:2',
            'total' => 'decimal:2',
            'saldo' => 'decimal:2',
        ];
    }

    /**
     * Get the client assigned to the quote.
     */
    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    /**
     * Get the equipment assigned to the quote.
     */
    public function equipo(): BelongsTo
    {
        return $this->belongsTo(Equipo::class);
    }

    /**
     * Get the user that created the quote.
     */
    public function creadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creado_por_user_id');
    }

    /**
     * Get the partner the quote belongs to.
     */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    /**
     * Get the items for the quote.
     */
    public function items(): HasMany
    {
        return $this->hasMany(CotizacionItem::class);
    }

    /**
     * Determine if the quote can still be edited.
     */
    public function esEditable(): bool
    {
        return in_array($this->estado, self::ESTADOS_EDITABLES, true);
    }
}
