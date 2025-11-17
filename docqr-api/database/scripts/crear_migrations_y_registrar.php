<?php
/**
 * Script para crear tabla migrations y registrar migraciones de qr_files
 * Ejecutar: php database/crear_migrations_y_registrar.php
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

try {
    echo "=== Configurando sistema de migraciones ===\n\n";

    // Paso 1: Crear tabla migrations si no existe
    if (!Schema::hasTable('migrations')) {
        echo "Paso 1: Creando tabla migrations...\n";
        DB::statement("
            CREATE TABLE `migrations` (
              `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
              `migration` varchar(255) NOT NULL,
              `batch` int(11) NOT NULL,
              PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
        echo "✓ Tabla migrations creada.\n\n";
    } else {
        echo "✓ Tabla migrations ya existe.\n\n";
    }

    // Paso 2: Registrar migraciones de qr_files
    echo "Paso 2: Registrando migraciones de qr_files...\n";
    
    $batch = DB::table('migrations')->max('batch') ?? 0;
    $batch++;

    $migrations = [
        '2025_11_15_000000_create_qr_files_table',
        '2025_11_16_000000_add_archived_fields_to_qr_files',
        '2025_11_16_000001_add_file_deleted_at_to_qr_files',
    ];

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

    // Paso 3: Verificación
    echo "\n=== Verificación ===\n";
    $registradas = DB::table('migrations')
        ->whereIn('migration', $migrations)
        ->get();

    if ($registradas->count() > 0) {
        echo "Migraciones registradas:\n";
        foreach ($registradas as $mig) {
            echo "  - {$mig->migration} (batch: {$mig->batch})\n";
        }
    } else {
        echo "⚠ No se encontraron migraciones registradas.\n";
    }

    // Verificar que la tabla qr_files existe
    if (Schema::hasTable('qr_files')) {
        echo "\n✓ Tabla qr_files existe.\n";
    } else {
        echo "\n⚠ Tabla qr_files NO existe. Debes crearla primero.\n";
    }

    echo "\n✓ Proceso completado.\n";

} catch (\Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    if (strpos($e->getMessage(), 'migrations') !== false) {
        echo "\nSugerencia: Ejecuta este SQL en phpMyAdmin para crear la tabla migrations:\n";
        echo "CREATE TABLE `migrations` (\n";
        echo "  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,\n";
        echo "  `migration` varchar(255) NOT NULL,\n";
        echo "  `batch` int(11) NOT NULL,\n";
        echo "  PRIMARY KEY (`id`)\n";
        echo ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;\n";
    }
    exit(1);
}

