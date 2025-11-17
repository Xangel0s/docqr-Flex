<?php
/**
 * Script para aplicar migraciones usando SQL directo
 * Evita problemas con tablespace usando consultas SQL nativas
 * Ejecutar: php database/aplicar_migraciones_sql_directo.php
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

try {
    echo "=== Aplicando Migraciones (SQL Directo) ===\n\n";

    // Paso 1: Verificar/Crear tabla migrations con SQL directo
    echo "Paso 1: Verificando tabla migrations...\n";
    
    try {
        $result = DB::select("SHOW TABLES LIKE 'migrations'");
        if (empty($result)) {
            echo "⚠ Tabla migrations no existe. Creándola...\n";
            DB::statement("
                CREATE TABLE `migrations` (
                  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
                  `migration` varchar(255) NOT NULL,
                  `batch` int(11) NOT NULL,
                  PRIMARY KEY (`id`),
                  KEY `migrations_migration_index` (`migration`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");
            echo "✓ Tabla migrations creada.\n\n";
        } else {
            echo "✓ Tabla migrations existe.\n\n";
        }
    } catch (\Exception $e) {
        echo "⚠ Error al verificar migrations: " . $e->getMessage() . "\n";
        echo "Intentando crear de todas formas...\n";
        try {
            DB::statement("DROP TABLE IF EXISTS `migrations`");
            DB::statement("
                CREATE TABLE `migrations` (
                  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
                  `migration` varchar(255) NOT NULL,
                  `batch` int(11) NOT NULL,
                  PRIMARY KEY (`id`),
                  KEY `migrations_migration_index` (`migration`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");
            echo "✓ Tabla migrations recreada.\n\n";
        } catch (\Exception $e2) {
            echo "✗ No se pudo crear tabla migrations: " . $e2->getMessage() . "\n";
            exit(1);
        }
    }

    // Paso 2: Obtener el siguiente batch usando SQL directo
    echo "Paso 2: Obteniendo siguiente batch...\n";
    $result = DB::select("SELECT COALESCE(MAX(batch), 0) as max_batch FROM migrations");
    $maxBatch = $result[0]->max_batch ?? 0;
    $nextBatch = $maxBatch + 1;
    echo "✓ Siguiente batch: {$nextBatch}\n\n";

    // Paso 3: Registrar migraciones usando SQL directo
    echo "Paso 3: Registrando migraciones de qr_files...\n";
    
    $migrations = [
        '2025_11_15_000000_create_qr_files_table' => 'Crear tabla qr_files',
        '2025_11_16_000000_add_archived_fields_to_qr_files' => 'Agregar campos archived',
        '2025_11_16_000001_add_file_deleted_at_to_qr_files' => 'Agregar campo original_file_deleted_at',
    ];

    $registradas = 0;
    $yaExistentes = 0;

    foreach ($migrations as $migration => $descripcion) {
        // Verificar si existe usando SQL directo
        $exists = DB::select("
            SELECT COUNT(*) as count 
            FROM migrations 
            WHERE migration = ?
        ", [$migration]);
        
        $count = $exists[0]->count ?? 0;

        if ($count == 0) {
            // Insertar usando SQL directo
            DB::insert("
                INSERT INTO migrations (migration, batch) 
                VALUES (?, ?)
            ", [$migration, $nextBatch]);
            echo "✓ Registrada: {$migration} - {$descripcion}\n";
            $registradas++;
        } else {
            echo "- Ya existe: {$migration}\n";
            $yaExistentes++;
        }
    }

    echo "\n";

    // Paso 4: Verificación final
    echo "=== Verificación Final ===\n";
    
    // Verificar migraciones registradas usando SQL directo
    $migrationNames = array_keys($migrations);
    $placeholders = implode(',', array_fill(0, count($migrationNames), '?'));
    $migracionesRegistradas = DB::select("
        SELECT migration, batch 
        FROM migrations 
        WHERE migration IN ({$placeholders})
        ORDER BY batch, migration
    ", $migrationNames);

    echo "Migraciones registradas (" . count($migracionesRegistradas) . "):\n";
    foreach ($migracionesRegistradas as $mig) {
        echo "  ✓ {$mig->migration} (batch: {$mig->batch})\n";
    }

    // Verificar estructura de qr_files
    echo "\nEstructura de tabla qr_files:\n";
    $columnas = DB::select("SHOW COLUMNS FROM qr_files");
    $columnasEsperadas = [
        'id', 'qr_id', 'document_id', 'folder_name', 'original_filename',
        'file_path', 'original_file_deleted_at', 'qr_path', 'final_path',
        'archive_path', 'file_size', 'qr_position', 'status', 'archived',
        'scan_count', 'last_scanned_at', 'created_at', 'updated_at', 'deleted_at'
    ];
    
    $columnasEncontradas = array_map(function($col) {
        return $col->Field;
    }, $columnas);

    $faltantes = array_diff($columnasEsperadas, $columnasEncontradas);
    $extra = array_diff($columnasEncontradas, $columnasEsperadas);

    echo "  Columnas encontradas: " . count($columnasEncontradas) . "\n";
    if (!empty($faltantes)) {
        echo "  ⚠ Columnas faltantes: " . implode(', ', $faltantes) . "\n";
    }
    if (!empty($extra)) {
        echo "  ℹ Columnas adicionales: " . implode(', ', $extra) . "\n";
    }

    // Resumen
    echo "\n=== Resumen ===\n";
    echo "Migraciones nuevas registradas: {$registradas}\n";
    echo "Migraciones que ya existían: {$yaExistentes}\n";
    echo "Total de migraciones de qr_files: " . count($migracionesRegistradas) . "\n";
    
    if ($registradas > 0 || count($migracionesRegistradas) == count($migrations)) {
        echo "\n✓ ¡Migraciones aplicadas correctamente!\n";
        echo "✓ El sistema está listo para usar.\n";
        echo "\nAhora puedes usar 'php artisan migrate:status' para verificar.\n";
    } else {
        echo "\n⚠ Algunas migraciones no se registraron correctamente.\n";
    }

} catch (\Exception $e) {
    echo "\n✗ ERROR: " . $e->getMessage() . "\n";
    echo "\nTrace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}

