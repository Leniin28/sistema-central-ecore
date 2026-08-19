<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * NULL means that the technical cost has not been confirmed yet. Existing
     * values, including explicit zeroes, are preserved by this schema change.
     */
    public function up(): void
    {
        Schema::table('ordenes_servicio', function (Blueprint $table) {
            $table->decimal('costo_tecnico', 10, 2)->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        Schema::table('ordenes_servicio', function (Blueprint $table) {
            $table->decimal('costo_tecnico', 10, 2)->default(0)->change();
        });
    }
};
