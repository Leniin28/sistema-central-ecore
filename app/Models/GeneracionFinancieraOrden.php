<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'orden_servicio_id',
    'tipo',
    'actor_user_id',
    'modelo_financiero',
    'fecha',
    'metadata',
])]
class GeneracionFinancieraOrden extends Model
{
    protected $table = 'generaciones_financieras_orden';

    public const TIPO_ENTREGA = 'entrega';

    protected function casts(): array
    {
        return [
            'fecha' => 'date',
            'metadata' => 'array',
        ];
    }

    public function ordenServicio(): BelongsTo
    {
        return $this->belongsTo(OrdenServicio::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function movimientosFinancieros(): HasMany
    {
        return $this->hasMany(MovimientoFinanciero::class, 'generacion_financiera_orden_id');
    }

    /** Anulaciones estructuradas registradas contra este lote (a lo sumo una, en la práctica). */
    public function anulaciones(): HasMany
    {
        return $this->hasMany(AjusteFinancieroOrden::class, 'generacion_financiera_orden_id');
    }

    public function estaAnulada(): bool
    {
        return $this->anulaciones()->where('tipo', AjusteFinancieroOrden::TIPO_ANULACION_ENTREGA)->exists();
    }
}
