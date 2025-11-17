<?php

/**
 * Script para corregir la columna file_size en la tabla document
 * 
 * Cambia file_size de INT a VARCHAR para permitir valores como "298.11 KB"
 * 
 * USO:
 * php database/scripts/fix_file_size_column.php
 */

$rootDir = realpath(__DIR__ . '/../..');

if (!$rootDir) {
    $rootDir = dirname(__DIR__, 2);
}

$autoloadPath = $rootDir . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
if (!file_exists($autoloadPath)) {
    die("❌ ERROR: No se encontró vendor/autoload.php. Ejecuta 'composer install' primero.\n");
}

require $autoloadPath;

$bootstrapPath = $rootDir . DIRECTORY_SEPARATOR . 'bootstrap' . DIRECTORY_SEPARATOR . 'app.php';
if (!file_exists($bootstrapPath)) {
    die("❌ ERROR: No se encontró bootstrap/app.php.\n");
}

$app = require_once $bootstrapPath;
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

echo "\n=== CORRECCIÓN DE COLUMNA file_size ===\n\n";

try {
    // Verificar si la tabla existe
    if (!Schema::hasTable('document')) {
        echo "❌ La tabla 'document' no existe.\n\n";
        exit(1);
    }
    
    // Verificar el tipo actual de la columna
    $columns = DB::select("DESCRIBE document");
    $fileSizeColumn = null;
    foreach ($columns as $column) {
        if ($column->Field === 'file_size') {
            $fileSizeColumn = $column;
            break;
        }
    }
    
    if (!$fileSizeColumn) {
        echo "❌ La columna 'file_size' no existe en la tabla 'document'.\n\n";
        exit(1);
    }
    
    echo "📋 Tipo actual de file_size: {$fileSizeColumn->Type}\n\n";
    
    // Si ya es VARCHAR, no hacer nada
    if (stripos($fileSizeColumn->Type, 'varchar') !== false || stripos($fileSizeColumn->Type, 'text') !== false) {
        echo "✅ La columna 'file_size' ya es VARCHAR. No se necesita modificar.\n\n";
        exit(0);
    }
    
    // Cambiar a VARCHAR(50) para permitir strings como "298.11 KB"
    echo "🔄 Cambiando file_size de {$fileSizeColumn->Type} a VARCHAR(50)...\n";
    
    DB::statement("ALTER TABLE `document` MODIFY COLUMN `file_size` VARCHAR(50) NOT NULL DEFAULT ''");
    
    echo "✅ Columna 'file_size' cambiada exitosamente a VARCHAR(50).\n\n";
    echo "💡 Ahora puedes importar el SQL antiguo sin warnings de truncamiento.\n";
    echo "   El script de migración convertirá estos strings a bytes automáticamente.\n\n";
    
} catch (\Exception $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n\n";
    exit(1);
}

