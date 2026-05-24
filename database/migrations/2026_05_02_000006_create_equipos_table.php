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
        Schema::create('equipos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cliente_id')->constrained('clientes')->restrictOnDelete();
            $table->string('tipo_equipo');
            $table->string('marca');
            $table->string('modelo')->nullable();
            $table->string('numero_serie')->nullable();
            $table->string('password_equipo')->nullable();
            $table->text('estado_fisico_inicial')->nullable();
            $table->text('accesorios_recibidos')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('equipos');
    }
};
