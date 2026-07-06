<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Idempotency record for a change operation applied to a service order from the
 * internal OpenClaw API. See the openclaw_order_changes migration.
 */
#[Fillable(['orden_servicio_id', 'external_id'])]
class OpenClawOrderChange extends Model
{
    protected $table = 'openclaw_order_changes';

    public function ordenServicio(): BelongsTo
    {
        return $this->belongsTo(OrdenServicio::class);
    }
}
