<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Hacer campos de archivo nullable para permitir documentos sin PDF inicial
     * (necesario para el flujo "Adjuntar a QR")
     */
    public function up(): void
    {
        Schema::table('qr_files', function (Blueprint $table) {
            // Hacer file_path nullable (para documentos creados sin PDF en flujo "Adjuntar")
            $table->string('file_path', 500)->nullable()->change();
            
            // Hacer original_filename nullable (no tiene sentido si no hay archivo)
            $table->string('original_filename', 255)->nullable()->change();
            
            // Hacer file_size nullable (no tiene sentido si no hay archivo)
            $table->unsignedInteger('file_size')->nullable()->change();
        });
    }

    /**
     * Revertir los cambios
     */
    public function down(): void
    {
        Schema::table('qr_files', function (Blueprint $table) {
            // NOTA: Esto puede fallar si hay registros con valores NULL
            // En producción, primero actualizar los registros NULL antes de revertir
            $table->string('file_path', 500)->nullable(false)->change();
            $table->string('original_filename', 255)->nullable(false)->change();
            $table->unsignedInteger('file_size')->nullable(false)->change();
        });
    }
};

