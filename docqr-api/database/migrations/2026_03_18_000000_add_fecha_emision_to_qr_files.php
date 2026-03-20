<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Agregar campo fecha_emision a qr_files para Inacal
     */
    public function up(): void
    {
        Schema::table('qr_files', function (Blueprint $table) {
            $table->date('fecha_emision')->nullable()->after('original_filename');
        });
    }

    /**
     * Revertir la migración
     */
    public function down(): void
    {
        Schema::table('qr_files', function (Blueprint $table) {
            $table->dropColumn('fecha_emision');
        });
    }
};
