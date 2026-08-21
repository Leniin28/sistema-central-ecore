<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Entidad explícita para ajustes/correcciones financieras post-entrega (FASE
 * H.1): reembolsos, correcciones de costo interno, correcciones de comisión y
 * anulaciones de entrega. Deliberadamente separada de
 * movimientos_financieros.movimiento_original_id, que representa únicamente
 * la reversión V1 de un movimiento manual — este ajuste es el evento de
 * negocio; los movimientos compensatorios que genera se enlazan aquí via
 * movimientos_financieros.ajuste_financiero_orden_id, nunca via
 * movimiento_original_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ajustes_financieros_orden', function (Blueprint $table) {
            $table->id();
            $table->foreignId('orden_servicio_id')->constrained('ordenes_servicio')->restrictOnDelete();
            $table->string('tipo', 40);
            $table->text('motivo');
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->date('fecha');

            // Línea afectada cuando el ajuste corrige una línea (servicio/refacción).
            // No es una FK real (puede apuntar a orden_servicio_detalles u
            // orden_servicio_refacciones según linea_tipo) para no forzar dos
            // columnas FK nulas por fila.
            $table->string('linea_tipo', 20)->nullable();
            $table->unsignedBigInteger('linea_id')->nullable();

            $table->decimal('valor_anterior', 12, 2)->nullable();
            $table->decimal('valor_nuevo', 12, 2)->nullable();
            $table->decimal('delta', 12, 2)->nullable();

            // Lote de generación que este ajuste anula, únicamente para
            // tipo=anulacion_entrega.
            $table->foreignId('generacion_financiera_orden_id')->nullable()
                ->constrained('generaciones_financieras_orden')
                ->nullOnDelete();

            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['orden_servicio_id', 'tipo']);
        });

        Schema::table('movimientos_financieros', function (Blueprint $table) {
            $table->foreignId('ajuste_financiero_orden_id')->nullable()
                ->after('generacion_financiera_orden_id')
                ->constrained('ajustes_financieros_orden')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('movimientos_financieros', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ajuste_financiero_orden_id');
        });

        Schema::dropIfExists('ajustes_financieros_orden');
    }
};
