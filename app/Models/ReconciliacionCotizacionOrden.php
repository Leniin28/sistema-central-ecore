<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'case_key',
    'fingerprint',
    'cutoff_commit',
    'cutoff_at',
    'cotizacion_canonica_id',
    'orden_canonica_id',
    'aplicado_por_user_id',
    'cotizaciones_origen_ids',
    'ordenes_origen_ids',
    'snapshot_antes',
    'snapshot_despues',
    'aplicado_at',
])]
class ReconciliacionCotizacionOrden extends Model
{
    protected $table = 'reconciliaciones_cotizacion_orden';

    protected function casts(): array
    {
        return [
            'cutoff_at' => 'immutable_datetime',
            'cotizaciones_origen_ids' => 'array',
            'ordenes_origen_ids' => 'array',
            'snapshot_antes' => 'array',
            'snapshot_despues' => 'array',
            'aplicado_at' => 'immutable_datetime',
        ];
    }

    public function cotizacionCanonica(): BelongsTo
    {
        return $this->belongsTo(Cotizacion::class, 'cotizacion_canonica_id');
    }

    public function ordenCanonica(): BelongsTo
    {
        return $this->belongsTo(OrdenServicio::class, 'orden_canonica_id');
    }

    public function aplicadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'aplicado_por_user_id');
    }
}
