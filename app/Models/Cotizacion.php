<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Validation\ValidationException;

#[Fillable([
    'cotizacion_canonica_id',
    'folio',
    'cliente_id',
    'equipo_id',
    'creado_por_user_id',
    'partner_id',
    'fecha',
    'vigencia',
    'estado',
    'tipo_recepcion',
    'direccion_recepcion',
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
     * The ways the equipment can be received.
     *
     * @var list<string>
     */
    public const TIPOS_RECEPCION = ['en_negocio', 'recogido_a_domicilio'];

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

    /** Get the existing or newly created order linked to this quote. */
    public function ordenServicio(): HasOne
    {
        return $this->hasOne(OrdenServicio::class);
    }

    /** Get the financial movements registered from this quote. */
    public function movimientosFinancieros(): HasMany
    {
        return $this->hasMany(MovimientoFinanciero::class);
    }

    public function cotizacionCanonica(): BelongsTo
    {
        return $this->belongsTo(self::class, 'cotizacion_canonica_id');
    }

    /**
     * Determine if the quote can still be edited.
     */
    public function esEditable(): bool
    {
        return ! $this->esAbsorbida()
            && in_array($this->estado, self::ESTADOS_EDITABLES, true);
    }

    /** Determine whether this quote was absorbed by a canonical quote. */
    public function esAbsorbida(): bool
    {
        return $this->cotizacion_canonica_id !== null;
    }

    /** Reject any operational use while preserving read-only access. */
    public function asegurarOperativa(string $campo = 'cotizacion'): void
    {
        if (! $this->esAbsorbida()) {
            return;
        }

        throw ValidationException::withMessages([
            $campo => 'La cotización consolidada es histórica y no admite operaciones.',
        ]);
    }

    /**
     * Determine if the equipment was picked up at the client's address.
     */
    public function esRecogidaADomicilio(): bool
    {
        return $this->tipo_recepcion === 'recogido_a_domicilio';
    }

    /**
     * Get the human readable label for the reception type.
     */
    public function etiquetaRecepcion(): string
    {
        return $this->esRecogidaADomicilio() ? 'Recogido a domicilio' : 'En negocio';
    }
}
