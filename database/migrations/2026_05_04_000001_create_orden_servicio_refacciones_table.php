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
        Schema::create('orden_servicio_refacciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('orden_servicio_id')->constrained('ordenes_servicio')->cascadeOnDelete();
            $table->string('descripcion');
            $table->unsignedInteger('cantidad')->default(1);
            $table->decimal('costo_unitario', 10, 2)->default(0);
            $table->decimal('precio_unitario_cliente', 10, 2)->default(0);
            $table->decimal('costo_total', 10, 2)->default(0);
            $table->decimal('precio_total_cliente', 10, 2)->default(0);
            $table->decimal('utilidad_refaccion', 10, 2)->default(0);
            $table->text('notas')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orden_servicio_refacciones');
    }
};
