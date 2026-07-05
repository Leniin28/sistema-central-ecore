<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Additive columns for the internal reception API (OpenClaw):
     * - external_id: idempotency key (e.g. Telegram message id) so retries don't duplicate.
     * - origen: capture channel (e.g. telegram_foto_etiqueta) for auditing.
     * Both nullable; existing flows never set them, so nothing breaks.
     */
    public function up(): void
    {
        Schema::table('ordenes_servicio', function (Blueprint $table) {
            $table->string('external_id')->nullable()->unique()->after('folio');
            $table->string('origen')->nullable()->after('external_id');
        });
    }

    public function down(): void
    {
        Schema::table('ordenes_servicio', function (Blueprint $table) {
            $table->dropUnique(['external_id']);
            $table->dropColumn(['external_id', 'origen']);
        });
    }
};
