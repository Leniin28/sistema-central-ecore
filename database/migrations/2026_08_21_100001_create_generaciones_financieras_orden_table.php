<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trazabilidad prospectiva de generación financiera (FASE H.2): un lote/evento
 * explícito que agrupa los movimientos creados por UNA ejecución de
 * GenerarFinanzasOrdenServicio, para poder identificarlos sin depender de
 * descripción/fecha/categoría. Órdenes/movimientos ya existentes no reciben
 * lote (sin backfill especulativo): sólo las entregas posteriores a esta
 * migración quedan trazadas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generaciones_financieras_orden', function (Blueprint $table) {
            $table->id();
            $table->foreignId('orden_servicio_id')->constrained('ordenes_servicio')->restrictOnDelete();
            $table->string('tipo', 30)->default('entrega');
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('modelo_financiero', 32);
            $table->date('fecha');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['orden_servicio_id', 'tipo']);
        });

        Schema::table('movimientos_financieros', function (Blueprint $table) {
            $table->foreignId('generacion_financiera_orden_id')->nullable()
                ->after('cotizacion_id')
                ->constrained('generaciones_financieras_orden')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('movimientos_financieros', function (Blueprint $table) {
            $table->dropConstrainedForeignId('generacion_financiera_orden_id');
        });

        Schema::dropIfExists('generaciones_financieras_orden');
    }
};
