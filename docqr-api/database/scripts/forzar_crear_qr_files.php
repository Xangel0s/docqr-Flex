<?php
/**
 * Script para forzar la creación de la tabla qr_files
 * Elimina el tablespace huérfano y crea la tabla
 * Ejecutar: php database/forzar_crear_qr_files.php
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

try {
    echo "=== Forzando creación de tabla qr_files ===\n\n";

    // Paso 1: Intentar eliminar la tabla
    echo "Paso 1: Eliminando tabla si existe...\n";
    try {
        DB::statement("DROP TABLE IF EXISTS `qr_files`");
        echo "✓ Tabla eliminada (si existía).\n";
    } catch (\Exception $e) {
        echo "⚠ Error: " . $e->getMessage() . "\n";
        echo "Continuando...\n";
    }

    // Paso 2: Esperar un momento
    echo "\nPaso 2: Esperando 2 segundos...\n";
    sleep(2);

    // Paso 3: Intentar crear la tabla con un nombre temporal primero
    echo "\nPaso 3: Creando tabla temporal para limpiar tablespace...\n";
    try {
        DB::statement("DROP TABLE IF EXISTS `qr_files_temp_cleanup`");
        DB::statement("
            CREATE TABLE `qr_files_temp_cleanup` (
              `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
              PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
        echo "✓ Tabla temporal creada.\n";
        
        // Eliminar la temporal
        DB::statement("DROP TABLE `qr_files_temp_cleanup`");
        echo "✓ Tabla temporal eliminada.\n";
    } catch (\Exception $e) {
        echo "⚠ No se pudo crear tabla temporal: " . $e->getMessage() . "\n";
    }

    // Paso 4: Esperar otro momento
    sleep(1);

    // Paso 5: Crear la tabla real
    echo "\nPaso 4: Creando tabla qr_files...\n";
    $sql = "
        CREATE TABLE `qr_files` (
          `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
          `qr_id` varchar(32) NOT NULL,
          `document_id` int(11) UNSIGNED DEFAULT NULL,
          `folder_name` varchar(100) NOT NULL,
          `original_filename` varchar(255) NOT NULL,
          `file_path` varchar(500) NOT NULL,
          `original_file_deleted_at` timestamp NULL DEFAULT NULL COMMENT 'Fecha en que se eliminó el archivo original',
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

    try {
        DB::statement($sql);
        echo "✓ Tabla qr_files creada exitosamente.\n\n";
    } catch (\Exception $e) {
        if (strpos($e->getMessage(), 'Tablespace') !== false) {
            echo "\n✗ ERROR: Tablespace huérfano detectado.\n\n";
            echo "SOLUCIÓN MANUAL REQUERIDA:\n";
            echo "1. Detén MySQL desde XAMPP Control Panel\n";
            echo "2. Ve a: C:\\xampp\\mysql\\data\\eccohgon_docqr\\\n";
            echo "3. Elimina los archivos: qr_files.ibd y qr_files.frm (si existen)\n";
            echo "4. Inicia MySQL nuevamente\n";
            echo "5. Ejecuta el script SQL: database/SOLUCION_TABLESPACE.sql en phpMyAdmin\n\n";
            exit(1);
        } else {
            throw $e;
        }
    }

    // Paso 6: Verificar
    echo "Paso 5: Verificando tabla...\n";
    $columns = DB::select("SHOW COLUMNS FROM qr_files");
    echo "✓ Tabla verificada. Columnas: " . count($columns) . "\n";
    foreach ($columns as $col) {
        echo "  - {$col->Field}\n";
    }

    // Paso 7: Registrar migraciones
    echo "\nPaso 6: Registrando migraciones...\n";
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
            } else {
                echo "- Migración ya existe: {$migration}\n";
            }
        }
    } catch (\Exception $e) {
        echo "⚠ No se pudo registrar migraciones: " . $e->getMessage() . "\n";
    }

    echo "\n=== ✓ Proceso completado exitosamente ===\n";
    echo "\nLa tabla qr_files está lista para usar.\n";

} catch (\Exception $e) {
    echo "\n✗ ERROR: " . $e->getMessage() . "\n";
    echo "\nTrace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}

