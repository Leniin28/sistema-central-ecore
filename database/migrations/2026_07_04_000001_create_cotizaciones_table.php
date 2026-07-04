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
        Schema::create('cotizaciones', function (Blueprint $table) {
            $table->id();
            $table->string('folio')->unique();
            $table->foreignId('cliente_id')->constrained('clientes')->restrictOnDelete();
            $table->foreignId('equipo_id')->nullable()->constrained('equipos')->nullOnDelete();
            $table->foreignId('creado_por_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('partner_id')->nullable()->constrained('partners')->nullOnDelete();
            $table->date('fecha');
            $table->date('vigencia')->nullable();
            $table->string('estado')->default('borrador');
            $table->decimal('subtotal', 10, 2)->default(0);
            $table->decimal('descuento', 10, 2)->default(0);
            $table->decimal('anticipo', 10, 2)->default(0);
            $table->decimal('total', 10, 2)->default(0);
            $table->decimal('saldo', 10, 2)->default(0);
            $table->text('notas')->nullable();
            $table->string('external_id')->nullable()->unique();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cotizaciones');
    }
};
