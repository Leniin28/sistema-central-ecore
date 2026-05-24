<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'orden_servicio_id',
    'user_id',
    'estado_anterior',
    'estado_nuevo',
    'comentario',
])]
class HistorialEstado extends Model
{
    protected $table = 'historial_estados';

    /**
     * Get the service order that owns the status history entry.
     */
    public function ordenServicio(): BelongsTo
    {
        return $this->belongsTo(OrdenServicio::class);
    }

    /**
     * Get the user that registered the status change.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
