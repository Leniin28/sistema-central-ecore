<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'orden_servicio_id',
    'cliente_id',
    'partner_id',
    'tipo',
    'categoria',
    'monto',
    'descripcion',
    'fecha',
    'external_id',
])]
class MovimientoFinanciero extends Model
{
    protected $table = 'movimientos_financieros';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'monto' => 'decimal:2',
            'fecha' => 'date',
        ];
    }

    /**
     * Get the service order assigned to this financial movement.
     */
    public function ordenServicio(): BelongsTo
    {
        return $this->belongsTo(OrdenServicio::class);
    }

    /**
     * Get the client assigned to this financial movement.
     */
    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    /**
     * Get the partner assigned to this financial movement.
     */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }
}
