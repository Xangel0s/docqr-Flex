<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ejecutar las migraciones.
     * 
     * Agrega un campo para marcar cuando el archivo original fue eliminado,
     * manteniendo file_path como referencia histórica.
     */
    public function up(): void
    {
        Schema::table('qr_files', function (Blueprint $table) {
            $table->timestamp('original_file_deleted_at')->nullable()->after('file_path')
                  ->comment('Fecha en que se eliminó el archivo original (para ahorrar espacio)');
        });
    }

    /**
     * Revertir las migraciones.
     */
    public function down(): void
    {
        Schema::table('qr_files', function (Blueprint $table) {
            $table->dropColumn('original_file_deleted_at');
        });
    }
};

