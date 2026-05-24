<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['categoria_servicio_id', 'nombre', 'descripcion', 'precio_base', 'activo'])]
class Servicio extends Model
{
    /**
     * Get the category assigned to the service.
     */
    public function categoriaServicio(): BelongsTo
    {
        return $this->belongsTo(CategoriaServicio::class);
    }

    /**
     * Get the order details that use this service.
     */
    public function ordenServicioDetalles(): HasMany
    {
        return $this->hasMany(OrdenServicioDetalle::class);
    }
}
