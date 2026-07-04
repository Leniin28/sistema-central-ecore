<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'cliente_id',
    'tipo_equipo',
    'marca',
    'modelo',
    'numero_serie',
    'password_equipo',
    'estado_fisico_inicial',
    'accesorios_recibidos',
])]
class Equipo extends Model
{
    /**
     * Get the client that owns the equipment.
     */
    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    /**
     * Get the service orders registered for the equipment.
     */
    public function ordenesServicio(): HasMany
    {
        return $this->hasMany(OrdenServicio::class);
    }

    /**
     * Get the quotes registered for the equipment.
     */
    public function cotizaciones(): HasMany
    {
        return $this->hasMany(Cotizacion::class);
    }
}
