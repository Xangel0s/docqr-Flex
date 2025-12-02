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
        Schema::create('qr_files', function (Blueprint $table) {
            $table->id();
            $table->string('qr_id', 32)->unique()->index(); // Hash único para el QR
            $table->string('folder_name', 100)->index(); // Nombre de la carpeta
            $table->string('original_filename', 255); // Nombre original del archivo
            $table->string('file_path', 500); // Ruta del PDF original (storage/uploads/...)
            $table->string('qr_path', 500); // Ruta de la imagen QR (storage/qrcodes/...)
            $table->string('final_path', 500)->nullable(); // Ruta del PDF final con QR (storage/final/...)
            $table->unsignedInteger('file_size'); // Tamaño del archivo en bytes
            $table->json('qr_position')->nullable(); // Posición del QR: {x, y, width, height}
            $table->enum('status', ['uploaded', 'processing', 'completed', 'failed'])
                  ->default('uploaded'); // Estado del procesamiento
            $table->unsignedInteger('scan_count')->default(0); // Contador de escaneos
            $table->timestamp('last_scanned_at')->nullable(); // Fecha del último escaneo
            $table->timestamps(); // created_at, updated_at
            $table->softDeletes(); // deleted_at para borrado suave
        });
    }

    /**
     * Revertir las migraciones.
     */
    public function down(): void
    {
        Schema::dropIfExists('qr_files');
    }
};

