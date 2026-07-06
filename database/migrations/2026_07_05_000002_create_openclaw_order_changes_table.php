<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Idempotency ledger for change operations applied to a service order from the
     * internal API (OpenClaw). A repeated external_id (e.g. a Telegram message id)
     * must not apply the same change twice. Order creation keeps using
     * ordenes_servicio.external_id; this table is only for edit operations.
     */
    public function up(): void
    {
        Schema::create('openclaw_order_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('orden_servicio_id')->constrained('ordenes_servicio')->cascadeOnDelete();
            $table->string('external_id')->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('openclaw_order_changes');
    }
};
