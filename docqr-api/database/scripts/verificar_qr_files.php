<?php
/**
 * Script para verificar la estructura de la tabla qr_files
 * Ejecutar: php database/verificar_qr_files.php
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

try {
    if (!Schema::hasTable('qr_files')) {
        echo "✗ La tabla qr_files NO existe.\n";
        exit(1);
    }

    echo "✓ La tabla qr_files existe.\n\n";

    // Obtener columnas
    $columns = DB::select("SHOW COLUMNS FROM qr_files");
    
    echo "Columnas encontradas:\n";
    $columnNames = [];
    foreach ($columns as $column) {
        $columnNames[] = $column->Field;
        echo "  - {$column->Field} ({$column->Type})\n";
    }

    // Verificar campos requeridos
    $requiredFields = [
        'id', 'qr_id', 'document_id', 'folder_name', 'original_filename',
        'file_path', 'qr_path', 'final_path', 'file_size', 'qr_position',
        'status', 'scan_count', 'last_scanned_at', 'created_at', 'updated_at', 'deleted_at'
    ];

    $optionalFields = [
        'archived', 'archive_path', 'original_file_deleted_at'
    ];

    echo "\nVerificando campos requeridos:\n";
    $missing = [];
    foreach ($requiredFields as $field) {
        if (in_array($field, $columnNames)) {
            echo "  ✓ {$field}\n";
        } else {
            echo "  ✗ {$field} - FALTANTE\n";
            $missing[] = $field;
        }
    }

    echo "\nVerificando campos opcionales:\n";
    foreach ($optionalFields as $field) {
        if (in_array($field, $columnNames)) {
            echo "  ✓ {$field}\n";
        } else {
            echo "  - {$field} - No presente (se puede agregar)\n";
        }
    }

    if (!empty($missing)) {
        echo "\n✗ Faltan campos requeridos. Ejecuta las migraciones faltantes.\n";
        exit(1);
    }

    // Verificar índices
    $indexes = DB::select("SHOW INDEXES FROM qr_files");
    echo "\nÍndices encontrados:\n";
    foreach ($indexes as $index) {
        echo "  - {$index->Key_name} ({$index->Column_name})\n";
    }

    echo "\n✓ La tabla qr_files tiene la estructura correcta.\n";

} catch (\Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    exit(1);
}

