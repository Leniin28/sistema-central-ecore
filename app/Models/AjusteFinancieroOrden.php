<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'orden_servicio_id',
    'tipo',
    'motivo',
    'actor_user_id',
    'fecha',
    'linea_tipo',
    'linea_id',
    'valor_anterior',
    'valor_nuevo',
    'delta',
    'generacion_financiera_orden_id',
    'metadata',
])]
class AjusteFinancieroOrden extends Model
{
    protected $table = 'ajustes_financieros_orden';

    public const TIPO_REEMBOLSO_CLIENTE = 'reembolso_cliente';

    public const TIPO_CORRECCION_COSTO_SERVICIO = 'correccion_costo_servicio';

    public const TIPO_CORRECCION_COSTO_REFACCION = 'correccion_costo_refaccion';

    public const TIPO_CORRECCION_COMISION_RECEPCION = 'correccion_comision_recepcion';

    public const TIPO_CORRECCION_COMISION_LOGISTICA = 'correccion_comision_logistica';

    public const TIPO_ANULACION_ENTREGA = 'anulacion_entrega';

    protected function casts(): array
    {
        return [
            'fecha' => 'date',
            'valor_anterior' => 'decimal:2',
            'valor_nuevo' => 'decimal:2',
            'delta' => 'decimal:2',
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

    public function generacionFinanciera(): BelongsTo
    {
        return $this->belongsTo(GeneracionFinancieraOrden::class, 'generacion_financiera_orden_id');
    }

    public function movimientosFinancieros(): HasMany
    {
        return $this->hasMany(MovimientoFinanciero::class, 'ajuste_financiero_orden_id');
    }
}
