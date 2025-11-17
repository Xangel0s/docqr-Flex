<?php
/**
 * Script para arreglar la tabla qr_files (problema de tablespace)
 * Ejecutar: php database/fix_qr_files.php
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

try {
    echo "Arreglando tabla qr_files...\n\n";

    // Intentar descartar el tablespace si la tabla existe
    try {
        DB::statement("DROP TABLE IF EXISTS `qr_files`");
        echo "✓ Tabla eliminada.\n";
    } catch (\Exception $e) {
        echo "⚠ Error al eliminar: " . $e->getMessage() . "\n";
    }

    // Esperar un momento para que MySQL procese
    sleep(1);

    // Crear la tabla sin el tablespace problemático
    // Primero crear una estructura mínima
    try {
        $sql = "
            CREATE TABLE `qr_files` (
              `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
              `qr_id` varchar(32) NOT NULL,
              `document_id` int(11) UNSIGNED DEFAULT NULL,
              `folder_name` varchar(100) NOT NULL,
              `original_filename` varchar(255) NOT NULL,
              `file_path` varchar(500) NOT NULL,
              `original_file_deleted_at` timestamp NULL DEFAULT NULL,
              `qr_path` varchar(500) NOT NULL,
              `final_path` varchar(500) DEFAULT NULL,
              `archive_path` varchar(500) DEFAULT NULL,
              `file_size` int(10) UNSIGNED NOT NULL,
              `qr_position` json DEFAULT NULL,
              `status` enum('uploaded','processing','completed','failed') NOT NULL DEFAULT 'uploaded',
              `archived` tinyint(1) NOT NULL DEFAULT 0,
              `scan_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
              `last_scanned_at` timestamp NULL DEFAULT NULL,
              `created_at` timestamp NULL DEFAULT NULL,
              `updated_at` timestamp NULL DEFAULT NULL,
              `deleted_at` timestamp NULL DEFAULT NULL,
              PRIMARY KEY (`id`),
              UNIQUE KEY `qr_files_qr_id_unique` (`qr_id`),
              KEY `qr_files_qr_id_index` (`qr_id`),
              KEY `qr_files_folder_name_index` (`folder_name`),
              KEY `qr_files_document_id_index` (`document_id`),
              KEY `qr_files_archived_status_index` (`archived`, `status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ";

        DB::statement($sql);
        echo "✓ Tabla qr_files creada exitosamente.\n\n";

        // Verificar
        $columns = DB::select("SHOW COLUMNS FROM qr_files");
        echo "✓ Tabla verificada. Columnas: " . count($columns) . "\n";

        // Registrar migraciones
        $migrations = [
            '2025_11_15_000000_create_qr_files_table',
            '2025_11_16_000000_add_archived_fields_to_qr_files',
            '2025_11_16_000001_add_file_deleted_at_to_qr_files',
        ];

        try {
            $batch = DB::table('migrations')->max('batch') ?? 0;
            $batch++;

            foreach ($migrations as $migration) {
                $exists = DB::table('migrations')
                    ->where('migration', $migration)
                    ->exists();

                if (!$exists) {
                    DB::table('migrations')->insert([
                        'migration' => $migration,
                        'batch' => $batch
                    ]);
                    echo "✓ Migración registrada: {$migration}\n";
                }
            }
        } catch (\Exception $e) {
            echo "⚠ No se pudo registrar migraciones: " . $e->getMessage() . "\n";
        }

        echo "\n✓ Proceso completado exitosamente.\n";

    } catch (\Exception $e) {
        if (strpos($e->getMessage(), 'Tablespace') !== false) {
            echo "\n✗ Error de tablespace detectado.\n";
            echo "Por favor, ejecuta manualmente en MySQL:\n\n";
            echo "1. Conecta a MySQL: mysql -u root -p\n";
            echo "2. Selecciona la base de datos: USE eccohgon_docqr;\n";
            echo "3. Elimina los archivos del tablespace manualmente desde el sistema de archivos\n";
            echo "   (ubicación: C:/xampp/mysql/data/eccohgon_docqr/qr_files.*)\n";
            echo "4. O ejecuta: DROP TABLE IF EXISTS qr_files;\n";
            echo "5. Luego ejecuta este script nuevamente.\n\n";
            echo "O ejecuta el SQL directamente desde phpMyAdmin:\n";
            echo file_get_contents(__DIR__ . '/CREAR_QR_FILES.sql');
        } else {
            echo "✗ Error: " . $e->getMessage() . "\n";
        }
        exit(1);
    }

} catch (\Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    exit(1);
}

