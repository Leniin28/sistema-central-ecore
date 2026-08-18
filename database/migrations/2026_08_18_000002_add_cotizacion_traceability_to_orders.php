<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Links an order to its source quote and every transferred line to its
     * source item. Unique indexes make quote conversion idempotent even when
     * two requests race inside separate transactions.
     */
    public function up(): void
    {
        Schema::table('ordenes_servicio', function (Blueprint $table) {
            $table->foreignId('cotizacion_id')->nullable()->unique()->after('id')
                ->constrained('cotizaciones')->restrictOnDelete();
        });

        Schema::table('orden_servicio_detalles', function (Blueprint $table) {
            $table->foreignId('cotizacion_item_id')->nullable()->unique()->after('orden_servicio_id')
                ->constrained('cotizacion_items')->nullOnDelete();
        });

        Schema::table('orden_servicio_refacciones', function (Blueprint $table) {
            $table->foreignId('cotizacion_item_id')->nullable()->unique()->after('orden_servicio_id')
                ->constrained('cotizacion_items')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orden_servicio_refacciones', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cotizacion_item_id');
        });

        Schema::table('orden_servicio_detalles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cotizacion_item_id');
        });

        Schema::table('ordenes_servicio', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cotizacion_id');
        });
    }
};
