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
        Schema::table('movimientos_financieros', function (Blueprint $table) {
            $table->foreignId('movimiento_original_id')->nullable()->unique()
                ->constrained('movimientos_financieros')->nullOnDelete();
            $table->text('motivo_reversion')->nullable();
            $table->foreignId('revertido_por_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('movimientos_financieros', function (Blueprint $table) {
            $table->dropConstrainedForeignId('movimiento_original_id');
            $table->dropConstrainedForeignId('revertido_por_user_id');
            $table->dropColumn('motivo_reversion');
        });
    }
};
