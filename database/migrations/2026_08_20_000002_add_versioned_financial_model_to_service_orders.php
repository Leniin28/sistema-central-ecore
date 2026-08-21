<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Prepare the versioned financial model without changing current behavior.
     * Existing and newly created orders remain legacy during this phase.
     */
    public function up(): void
    {
        Schema::table('ordenes_servicio', function (Blueprint $table) {
            $table->decimal('comision_recepcion', 10, 2)->nullable()->default(null)->after('comision_logistica');
            $table->string('nota_recepcion', 255)->nullable()->default(null)->after('comision_recepcion');
            $table->string('modelo_financiero', 32)->default('legacy')->after('nota_recepcion');
        });
    }

    public function down(): void
    {
        Schema::table('ordenes_servicio', function (Blueprint $table) {
            $table->dropColumn(['comision_recepcion', 'nota_recepcion', 'modelo_financiero']);
        });
    }
};
