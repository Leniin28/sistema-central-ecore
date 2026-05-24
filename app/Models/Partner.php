<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['nombre', 'tipo_socio', 'comision_fija', 'activo'])]
class Partner extends Model
{
    /**
     * Get the users assigned to this partner.
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * Get the service orders received through this partner.
     */
    public function ordenesRecepcion(): HasMany
    {
        return $this->hasMany(OrdenServicio::class, 'partner_recepcion_id');
    }

    /**
     * Get the service orders assigned to this technical partner.
     */
    public function ordenesTecnicas(): HasMany
    {
        return $this->hasMany(OrdenServicio::class, 'partner_tecnico_id');
    }

    /**
     * Get the financial movements registered for this partner.
     */
    public function movimientosFinancieros(): HasMany
    {
        return $this->hasMany(MovimientoFinanciero::class);
    }
}
