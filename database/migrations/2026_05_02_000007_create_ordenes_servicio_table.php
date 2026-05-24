<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ordenes_servicio', function (Blueprint $table) {
            $table->id();
            $table->string('folio')->unique();
            $table->foreignId('cliente_id')->constrained('clientes')->restrictOnDelete();
            $table->foreignId('equipo_id')->nullable()->constrained('equipos')->nullOnDelete();
            $table->foreignId('creado_por_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('partner_recepcion_id')->nullable()->constrained('partners')->nullOnDelete();
            $table->foreignId('partner_tecnico_id')->nullable()->constrained('partners')->nullOnDelete();
            $table->string('tipo_recepcion');
            $table->string('estado');
            $table->decimal('total_cliente', 10, 2)->default(0);
            $table->decimal('costo_tecnico', 10, 2)->default(0);
            $table->decimal('comision_logistica', 10, 2)->default(0);
            $table->decimal('utilidad_neta', 10, 2)->default(0);
            $table->text('notas')->nullable();
            $table->dateTime('fecha_recepcion')->nullable();
            $table->dateTime('fecha_entrega')->nullable();
            $table->boolean('finanzas_generadas')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ordenes_servicio');
    }
};
