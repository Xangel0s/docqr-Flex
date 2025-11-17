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
        Schema::table('qr_files', function (Blueprint $table) {
            // Hacer qr_path nullable para permitir documentos sin QR inicialmente
            // Los QRs se generarán después con la URL correcta
            $table->string('qr_path', 500)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('qr_files', function (Blueprint $table) {
            // Revertir: hacer qr_path NOT NULL nuevamente
            // NOTA: Esto puede fallar si hay registros con qr_path NULL
            $table->string('qr_path', 500)->nullable(false)->change();
        });
    }
};
