<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Idempotency key for financial movements created from the internal API
     * (OpenClaw expenses). Null for panel/order-generated movements.
     */
    public function up(): void
    {
        Schema::table('movimientos_financieros', function (Blueprint $table) {
            $table->string('external_id')->nullable()->unique()->after('fecha');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('movimientos_financieros', function (Blueprint $table) {
            $table->dropUnique(['external_id']);
            $table->dropColumn('external_id');
        });
    }
};
