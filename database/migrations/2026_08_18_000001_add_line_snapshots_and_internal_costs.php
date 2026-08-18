<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Existing order details keep descripcion nullable because their original
     * sold text was never persisted and must not be recreated from the catalog.
     */
    public function up(): void
    {
        Schema::table('cotizacion_items', function (Blueprint $table) {
            $table->foreignId('servicio_id')->nullable()->after('cotizacion_id')->constrained('servicios')->nullOnDelete();
            $table->decimal('costo_unitario', 10, 2)->default(0)->after('precio_unitario');
            $table->decimal('costo_total', 10, 2)->default(0)->after('costo_unitario');
        });

        Schema::table('orden_servicio_detalles', function (Blueprint $table) {
            $table->dropForeign(['servicio_id']);
            $table->foreignId('servicio_id')->nullable()->change();
            $table->foreign('servicio_id')->references('id')->on('servicios')->nullOnDelete();
            $table->string('descripcion')->nullable()->after('servicio_id');
            $table->decimal('costo_unitario', 10, 2)->default(0)->after('precio_unitario');
            $table->decimal('costo_total', 10, 2)->default(0)->after('costo_unitario');
        });

        Schema::table('ordenes_servicio', function (Blueprint $table) {
            $table->decimal('utilidad_estimada', 10, 2)->default(0)->after('comision_logistica');
        });
    }

    /**
     * Requiring servicio_id again is only safe after removing ad-hoc lines, so
     * the nullable relationship is deliberately kept during rollback.
     */
    public function down(): void
    {
        Schema::table('ordenes_servicio', function (Blueprint $table) {
            $table->dropColumn('utilidad_estimada');
        });

        Schema::table('orden_servicio_detalles', function (Blueprint $table) {
            $table->dropForeign(['servicio_id']);
            $table->dropColumn(['descripcion', 'costo_unitario', 'costo_total']);
        });

        Schema::table('cotizacion_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('servicio_id');
            $table->dropColumn(['costo_unitario', 'costo_total']);
        });
    }
};
