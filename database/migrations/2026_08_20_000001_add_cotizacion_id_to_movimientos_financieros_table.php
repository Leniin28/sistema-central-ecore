<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('movimientos_financieros', function (Blueprint $table) {
            $table->foreignId('cotizacion_id')
                ->nullable()
                ->after('orden_servicio_id')
                ->constrained('cotizaciones')
                ->restrictOnDelete();

            $table->index(['cotizacion_id', 'tipo', 'categoria'], 'movimientos_cotizacion_tipo_categoria_idx');
        });
    }

    public function down(): void
    {
        Schema::table('movimientos_financieros', function (Blueprint $table) {
            $table->dropIndex('movimientos_cotizacion_tipo_categoria_idx');
            $table->dropConstrainedForeignId('cotizacion_id');
        });
    }
};
