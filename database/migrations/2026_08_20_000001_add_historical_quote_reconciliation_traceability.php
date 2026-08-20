<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cotizaciones', function (Blueprint $table) {
            $table->foreignId('cotizacion_canonica_id')->nullable()->after('id')
                ->constrained('cotizaciones')->restrictOnDelete();
        });

        Schema::table('cotizacion_items', function (Blueprint $table) {
            $table->foreignId('cotizacion_origen_id')->nullable()->after('cotizacion_id')
                ->constrained('cotizaciones')->restrictOnDelete();
        });

        Schema::table('ordenes_servicio', function (Blueprint $table) {
            $table->foreignId('orden_canonica_id')->nullable()->after('cotizacion_id')
                ->constrained('ordenes_servicio')->restrictOnDelete();
        });

        Schema::create('reconciliaciones_cotizacion_orden', function (Blueprint $table) {
            $table->id();
            $table->string('case_key')->unique();
            $table->char('fingerprint', 64);
            $table->char('cutoff_commit', 40);
            $table->timestamp('cutoff_at');
            $table->foreignId('cotizacion_canonica_id')->constrained('cotizaciones')->restrictOnDelete();
            $table->foreignId('orden_canonica_id')->constrained('ordenes_servicio')->restrictOnDelete();
            $table->foreignId('aplicado_por_user_id')->constrained('users')->restrictOnDelete();
            $table->json('cotizaciones_origen_ids');
            $table->json('ordenes_origen_ids');
            $table->json('snapshot_antes');
            $table->json('snapshot_despues');
            $table->timestamp('aplicado_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reconciliaciones_cotizacion_orden');

        Schema::table('ordenes_servicio', function (Blueprint $table) {
            $table->dropConstrainedForeignId('orden_canonica_id');
        });

        Schema::table('cotizacion_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cotizacion_origen_id');
        });

        Schema::table('cotizaciones', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cotizacion_canonica_id');
        });
    }
};
