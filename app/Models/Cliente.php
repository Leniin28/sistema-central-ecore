<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['nombre', 'telefono', 'correo', 'tipo_cliente', 'notas'])]
class Cliente extends Model
{
    /**
     * Get the equipment registered for the client.
     */
    public function equipos(): HasMany
    {
        return $this->hasMany(Equipo::class);
    }

    /**
     * Get the service orders registered for the client.
     */
    public function ordenesServicio(): HasMany
    {
        return $this->hasMany(OrdenServicio::class);
    }

    /**
     * Get the financial movements registered for the client.
     */
    public function movimientosFinancieros(): HasMany
    {
        return $this->hasMany(MovimientoFinanciero::class);
    }

    /**
     * Get the quotes registered for the client.
     */
    public function cotizaciones(): HasMany
    {
        return $this->hasMany(Cotizacion::class);
    }
}
