<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['nombre', 'descripcion'])]
class CategoriaServicio extends Model
{
    protected $table = 'categorias_servicio';

    /**
     * Get the services that belong to this category.
     */
    public function servicios(): HasMany
    {
        return $this->hasMany(Servicio::class);
    }
}
