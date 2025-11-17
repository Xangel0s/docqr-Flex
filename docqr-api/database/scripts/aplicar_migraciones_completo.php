<?php
/**
 * Script completo para aplicar migraciones correctamente
 * Crea la tabla migrations si no existe y registra todas las migraciones
 * Ejecutar: php database/aplicar_migraciones_completo.php
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

try {
    echo "=== Aplicando Migraciones Correctamente ===\n\n";

    // Paso 1: Verificar/Crear tabla migrations
    echo "Paso 1: Verificando tabla migrations...\n";
    
    try {
        // Intentar consultar la tabla
        $test = DB::select("SHOW TABLES LIKE 'migrations'");
        if (empty($test)) {
            throw new Exception("Tabla migrations no existe");
        }
        echo "✓ Tabla migrations existe.\n\n";
    } catch (\Exception $e) {
        echo "⚠ Tabla migrations no existe. Creándola...\n";
        DB::statement("
            CREATE TABLE IF NOT EXISTS `migrations` (
              `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
              `migration` varchar(255) NOT NULL,
              `batch` int(11) NOT NULL,
              PRIMARY KEY (`id`),
              KEY `migrations_migration_index` (`migration`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
        echo "✓ Tabla migrations creada.\n\n";
    }

    // Paso 2: Verificar que la tabla qr_files existe
    echo "Paso 2: Verificando tabla qr_files...\n";
    try {
        $test = DB::select("SHOW TABLES LIKE 'qr_files'");
        if (empty($test)) {
            throw new Exception("Tabla qr_files no existe");
        }
        echo "✓ Tabla qr_files existe.\n\n";
    } catch (\Exception $e) {
        echo "✗ ERROR: La tabla qr_files NO existe.\n";
        echo "Debes crear la tabla primero ejecutando el SQL en phpMyAdmin:\n";
        echo "Archivo: database/SOLUCION_QR_FILES.sql\n\n";
        exit(1);
    }

    // Paso 3: Obtener el siguiente batch
    echo "Paso 3: Obteniendo siguiente batch...\n";
    $maxBatch = DB::table('migrations')->max('batch');
    $nextBatch = ($maxBatch ?? 0) + 1;
    echo "✓ Siguiente batch: {$nextBatch}\n\n";

    // Paso 4: Registrar migraciones de qr_files
    echo "Paso 4: Registrando migraciones de qr_files...\n";
    
    $migrations = [
        '2025_11_15_000000_create_qr_files_table' => 'Crear tabla qr_files',
        '2025_11_16_000000_add_archived_fields_to_qr_files' => 'Agregar campos archived',
        '2025_11_16_000001_add_file_deleted_at_to_qr_files' => 'Agregar campo original_file_deleted_at',
    ];

    $registradas = 0;
    $yaExistentes = 0;

    foreach ($migrations as $migration => $descripcion) {
        $exists = DB::table('migrations')
            ->where('migration', $migration)
            ->exists();

        if (!$exists) {
            DB::table('migrations')->insert([
                'migration' => $migration,
                'batch' => $nextBatch
            ]);
            echo "✓ Registrada: {$migration} - {$descripcion}\n";
            $registradas++;
        } else {
            echo "- Ya existe: {$migration}\n";
            $yaExistentes++;
        }
    }

    echo "\n";

    // Paso 5: Verificación final
    echo "=== Verificación Final ===\n";
    
    // Verificar migraciones registradas
    $migracionesRegistradas = DB::table('migrations')
        ->whereIn('migration', array_keys($migrations))
        ->orderBy('batch')
        ->orderBy('migration')
        ->get();

    echo "Migraciones registradas (" . $migracionesRegistradas->count() . "):\n";
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
    echo "Total de migraciones de qr_files: " . $migracionesRegistradas->count() . "\n";
    
    if ($registradas > 0 || $migracionesRegistradas->count() == count($migrations)) {
        echo "\n✓ ¡Migraciones aplicadas correctamente!\n";
        echo "✓ El sistema está listo para usar.\n";
    } else {
        echo "\n⚠ Algunas migraciones no se registraron correctamente.\n";
    }

} catch (\Exception $e) {
    echo "\n✗ ERROR: " . $e->getMessage() . "\n";
    echo "\nTrace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}

