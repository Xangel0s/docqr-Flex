<?php
/**
 * Script para registrar las migraciones de qr_files
 * Ejecutar: php database/registrar_migraciones.php
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

try {
    echo "=== Registrando migraciones de qr_files ===\n\n";

    // Obtener el siguiente batch
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

    echo "\n=== Verificación ===\n";
    $registradas = DB::table('migrations')
        ->whereIn('migration', $migrations)
        ->get();

    echo "Migraciones registradas:\n";
    foreach ($registradas as $mig) {
        echo "  - {$mig->migration} (batch: {$mig->batch})\n";
    }

    echo "\n✓ Proceso completado exitosamente.\n";

} catch (\Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    echo "Trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}

