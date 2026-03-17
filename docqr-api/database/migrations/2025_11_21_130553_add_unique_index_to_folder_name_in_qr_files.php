<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Agrega índice UNIQUE a folder_name para garantizar que cada código sea único
     */
    public function up(): void
    {
        Schema::table('qr_files', function (Blueprint $table) {
            // Verificar si ya existe un índice único antes de crearlo
            $indexes = $this->getIndexMetadata('qr_files', 'qr_files_folder_name_unique');
            
            // Si no existe el índice único, crearlo
            if (empty($indexes)) {
                // Verificar si existe el índice no único antes de eliminarlo
                $nonUniqueIndexes = $this->getIndexMetadata('qr_files', 'qr_files_folder_name_index');
                if (!empty($nonUniqueIndexes)) {
                    try {
                        $table->dropIndex('qr_files_folder_name_index');
                    } catch (\Exception $e) {
                        // Ignorar si no se puede eliminar
                    }
                }
                
                // Crear índice único en folder_name
                // Esto garantiza que no haya códigos duplicados a nivel de base de datos
                $table->unique('folder_name', 'qr_files_folder_name_unique');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('qr_files', function (Blueprint $table) {
            // Verificar si existe el índice único antes de eliminarlo
            $indexes = $this->getIndexMetadata('qr_files', 'qr_files_folder_name_unique');
            
            if (!empty($indexes)) {
                // Eliminar índice único
                $table->dropUnique('qr_files_folder_name_unique');
            }
            
            // Restaurar índice no único (opcional, para mantener compatibilidad)
            try {
                $table->index('folder_name', 'qr_files_folder_name_index');
            } catch (\Exception $e) {
                // Ignorar si ya existe
            }
        });
    }

    /**
     * Obtener información de índices compatible con MySQL y SQLite
     */
    private function getIndexMetadata(string $table, string $indexName): array
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            $indexes = DB::select("PRAGMA index_list('{$table}')") ?: [];

            return array_values(array_filter($indexes, function (object $index) use ($indexName) {
                return ($index->name ?? null) === $indexName;
            }));
        }

        return DB::select("SHOW INDEX FROM {$table} WHERE Key_name = '{$indexName}'");
    }
};
