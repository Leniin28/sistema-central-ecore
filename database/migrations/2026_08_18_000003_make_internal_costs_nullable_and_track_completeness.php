<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Preserve the distinction between an uncaptured cost (NULL) and a real
     * zero cost. This changes only the schema; it never backfills history.
     */
    public function up(): void
    {
        Schema::table('cotizacion_items', function (Blueprint $table) {
            $table->decimal('costo_unitario', 10, 2)->nullable()->default(null)->change();
            $table->decimal('costo_total', 10, 2)->nullable()->default(null)->change();
        });

        Schema::table('orden_servicio_detalles', function (Blueprint $table) {
            $table->decimal('costo_unitario', 10, 2)->nullable()->default(null)->change();
            $table->decimal('costo_total', 10, 2)->nullable()->default(null)->change();
        });

        Schema::table('orden_servicio_refacciones', function (Blueprint $table) {
            $table->decimal('costo_unitario', 10, 2)->nullable()->default(null)->change();
            $table->decimal('costo_total', 10, 2)->nullable()->default(null)->change();
        });

        Schema::table('ordenes_servicio', function (Blueprint $table) {
            $table->boolean('costos_incompletos')->default(false)->after('utilidad_estimada');
        });
    }

    public function down(): void
    {
        Schema::table('ordenes_servicio', function (Blueprint $table) {
            $table->dropColumn('costos_incompletos');
        });

        Schema::table('orden_servicio_refacciones', function (Blueprint $table) {
            $table->decimal('costo_unitario', 10, 2)->default(0)->change();
            $table->decimal('costo_total', 10, 2)->default(0)->change();
        });

        Schema::table('orden_servicio_detalles', function (Blueprint $table) {
            $table->decimal('costo_unitario', 10, 2)->default(0)->change();
            $table->decimal('costo_total', 10, 2)->default(0)->change();
        });

        Schema::table('cotizacion_items', function (Blueprint $table) {
            $table->decimal('costo_unitario', 10, 2)->default(0)->change();
            $table->decimal('costo_total', 10, 2)->default(0)->change();
        });
    }
};
