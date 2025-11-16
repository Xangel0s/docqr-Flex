<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ejecutar las migraciones.
     */
    public function up(): void
    {
        Schema::table('qr_files', function (Blueprint $table) {
            // Marcar si el documento está archivado
            $table->boolean('archived')->default(false)->after('status');
            
            // Ruta del archivo ZIP donde está comprimido
            $table->string('archive_path', 500)->nullable()->after('final_path');
            
            // Índice para búsquedas rápidas de documentos no archivados
            $table->index(['archived', 'status']);
        });
    }

    /**
     * Revertir las migraciones.
     */
    public function down(): void
    {
        Schema::table('qr_files', function (Blueprint $table) {
            $table->dropIndex(['archived', 'status']);
            $table->dropColumn(['archived', 'archive_path']);
        });
    }
};

