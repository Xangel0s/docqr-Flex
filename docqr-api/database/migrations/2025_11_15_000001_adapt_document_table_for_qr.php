<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ejecutar las migraciones.
     * 
     * Esta migración adapta la tabla 'document' existente para trabajar con el nuevo sistema DocQR.
     * Agrega las columnas necesarias sin eliminar datos existentes.
     */
    public function up(): void
    {
        // Verificar si la tabla 'document' existe (base de datos antigua)
        if (Schema::hasTable('document')) {
            Schema::table('document', function (Blueprint $table) {
                // Agregar columnas para el nuevo sistema QR si no existen
                if (!Schema::hasColumn('document', 'qr_path')) {
                    $table->string('qr_path', 500)->nullable()->after('password_file');
                }
                if (!Schema::hasColumn('document', 'final_path')) {
                    $table->string('final_path', 500)->nullable()->after('qr_path');
                }
                if (!Schema::hasColumn('document', 'qr_position')) {
                    $table->json('qr_position')->nullable()->after('final_path');
                }
                if (!Schema::hasColumn('document', 'qr_status')) {
                    $table->enum('qr_status', ['uploaded', 'processing', 'completed', 'failed'])
                          ->default('uploaded')
                          ->after('qr_position');
                }
                if (!Schema::hasColumn('document', 'scan_count')) {
                    $table->unsignedInteger('scan_count')->default(0)->after('qr_status');
                }
                if (!Schema::hasColumn('document', 'last_scanned_at')) {
                    $table->timestamp('last_scanned_at')->nullable()->after('scan_count');
                }
                if (!Schema::hasColumn('document', 'folder_name')) {
                    $table->string('folder_name', 100)->nullable()->after('code');
                }
            });
        }

        // Crear tabla qr_files si no existe (para nuevos documentos)
        if (!Schema::hasTable('qr_files')) {
            Schema::create('qr_files', function (Blueprint $table) {
                $table->id();
                $table->string('qr_id', 32)->unique()->index(); // Hash único para el QR
                $table->unsignedInteger('document_id')->nullable()->index(); // Relación con tabla document existente
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
    }

    /**
     * Revertir las migraciones.
     */
    public function down(): void
    {
        // Revertir cambios en tabla document (eliminar columnas agregadas)
        if (Schema::hasTable('document')) {
            Schema::table('document', function (Blueprint $table) {
                $columns = ['folder_name', 'last_scanned_at', 'scan_count', 'qr_status', 'qr_position', 'final_path', 'qr_path'];
                foreach ($columns as $column) {
                    if (Schema::hasColumn('document', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        // Eliminar tabla qr_files si existe
        Schema::dropIfExists('qr_files');
    }
};

