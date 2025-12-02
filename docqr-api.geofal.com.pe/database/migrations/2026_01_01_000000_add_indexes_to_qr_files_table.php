<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Agregar índices para optimizar queries frecuentes
     */
    public function up(): void
    {
        Schema::table('qr_files', function (Blueprint $table) {
            // Índice único para qr_id (búsquedas frecuentes)
            if (!$this->indexExists('qr_files', 'qr_files_qr_id_unique')) {
                $table->unique('qr_id', 'qr_files_qr_id_unique');
            }
            
            // Índice para folder_name (filtros y búsquedas)
            if (!$this->indexExists('qr_files', 'qr_files_folder_name_index')) {
                $table->index('folder_name', 'qr_files_folder_name_index');
            }
            
            // Índice compuesto para status y created_at (listados y estadísticas)
            if (!$this->indexExists('qr_files', 'qr_files_status_created_at_index')) {
                $table->index(['status', 'created_at'], 'qr_files_status_created_at_index');
            }
            
            // Índice para scan_count (filtros de escaneos)
            if (!$this->indexExists('qr_files', 'qr_files_scan_count_index')) {
                $table->index('scan_count', 'qr_files_scan_count_index');
            }
            
            // Índice para last_scanned_at (estadísticas de escaneos)
            if (!$this->indexExists('qr_files', 'qr_files_last_scanned_at_index')) {
                $table->index('last_scanned_at', 'qr_files_last_scanned_at_index');
            }
            
            // Índice para archived (filtros de compresión)
            if (!$this->indexExists('qr_files', 'qr_files_archived_index')) {
                $table->index('archived', 'qr_files_archived_index');
            }
            
            // Índice compuesto para búsquedas por tipo y fecha
            if (!$this->indexExists('qr_files', 'qr_files_folder_name_created_at_index')) {
                $table->index(['folder_name', 'created_at'], 'qr_files_folder_name_created_at_index');
            }
        });
    }

    /**
     * Revertir los cambios
     */
    public function down(): void
    {
        Schema::table('qr_files', function (Blueprint $table) {
            $table->dropIndex('qr_files_qr_id_unique');
            $table->dropIndex('qr_files_folder_name_index');
            $table->dropIndex('qr_files_status_created_at_index');
            $table->dropIndex('qr_files_scan_count_index');
            $table->dropIndex('qr_files_last_scanned_at_index');
            $table->dropIndex('qr_files_archived_index');
            $table->dropIndex('qr_files_folder_name_created_at_index');
        });
    }
    
    /**
     * Verificar si un índice existe
     */
    private function indexExists(string $table, string $index): bool
    {
        $connection = Schema::getConnection();
        $databaseName = $connection->getDatabaseName();
        
        $result = $connection->select(
            "SELECT COUNT(*) as count FROM information_schema.statistics 
             WHERE table_schema = ? AND table_name = ? AND index_name = ?",
            [$databaseName, $table, $index]
        );
        
        return $result[0]->count > 0;
    }
};

