<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Agregar índices para optimizar consultas con múltiples usuarios simultáneos
     */
    public function up(): void
    {
        Schema::table('qr_files', function (Blueprint $table) {
            // Índice compuesto para búsquedas frecuentes (folder_name + status)
            if (!$this->hasIndex('qr_files', 'qr_files_folder_name_status_index')) {
                $table->index(['folder_name', 'status'], 'qr_files_folder_name_status_index');
            }
            
            // Índice para búsquedas por fecha (created_at)
            if (!$this->hasIndex('qr_files', 'qr_files_created_at_index')) {
                $table->index('created_at', 'qr_files_created_at_index');
            }
            
            // Índice para búsquedas por estado
            if (!$this->hasIndex('qr_files', 'qr_files_status_index')) {
                $table->index('status', 'qr_files_status_index');
            }
            
            // Índice para búsquedas por scan_count (para filtros)
            if (!$this->hasIndex('qr_files', 'qr_files_scan_count_index')) {
                $table->index('scan_count', 'qr_files_scan_count_index');
            }
            
            // Índice para búsquedas por last_scanned_at (para estadísticas)
            if (!$this->hasIndex('qr_files', 'qr_files_last_scanned_at_index')) {
                $table->index('last_scanned_at', 'qr_files_last_scanned_at_index');
            }
            
            // Índice para document_id (relación con tabla antigua)
            if (!$this->hasIndex('qr_files', 'qr_files_document_id_index')) {
                $table->index('document_id', 'qr_files_document_id_index');
            }
        });

        // Índices para tabla users (si no existen)
        if (Schema::hasTable('users')) {
            Schema::table('users', function (Blueprint $table) {
                // Índice para búsquedas por username (ya debería ser unique, pero por si acaso)
                if (!$this->hasIndex('users', 'users_username_index')) {
                    $table->index('username', 'users_username_index');
                }
                
                // Índice para búsquedas por is_active (filtros)
                if (!$this->hasIndex('users', 'users_is_active_index')) {
                    $table->index('is_active', 'users_is_active_index');
                }
            });
        }
    }

    /**
     * Revertir las migraciones.
     */
    public function down(): void
    {
        Schema::table('qr_files', function (Blueprint $table) {
            $table->dropIndex('qr_files_folder_name_status_index');
            $table->dropIndex('qr_files_created_at_index');
            $table->dropIndex('qr_files_status_index');
            $table->dropIndex('qr_files_scan_count_index');
            $table->dropIndex('qr_files_document_id_index');
        });

        if (Schema::hasTable('users')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropIndex('users_username_index');
                $table->dropIndex('users_is_active_index');
            });
        }
    }

    /**
     * Verificar si un índice existe
     */
    private function hasIndex(string $table, string $indexName): bool
    {
        $connection = Schema::getConnection();
        $databaseName = $connection->getDatabaseName();
        
        $result = $connection->select(
            "SELECT COUNT(*) as count 
             FROM information_schema.statistics 
             WHERE table_schema = ? 
             AND table_name = ? 
             AND index_name = ?",
            [$databaseName, $table, $indexName]
        );
        
        return $result[0]->count > 0;
    }
};
