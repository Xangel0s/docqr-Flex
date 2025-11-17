<?php

/**
 * Script para verificar documentos antiguos antes de migrar
 * 
 * USO:
 * php database/scripts/verificar_documentos_antiguos.php
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

echo "\n=== VERIFICACIÓN DE DOCUMENTOS ANTIGUOS ===\n\n";

// Verificar si la tabla existe
if (!DB::getSchemaBuilder()->hasTable('document')) {
    echo "❌ La tabla 'document' NO existe en la base de datos.\n";
    echo "   Necesitas importar el SQL primero.\n\n";
    exit(1);
}

echo "✅ La tabla 'document' existe.\n\n";

// Contar total de registros
$total = DB::table('document')->count();
echo "📊 Total de registros en 'document': {$total}\n";

// Contar activos (is_active = 1 o b'1')
$activos = DB::table('document')
    ->where('is_active', '=', 1)
    ->orWhere('is_active', '=', DB::raw("b'1'"))
    ->count();
echo "✅ Registros activos (is_active=1): {$activos}\n";

// Contar inactivos
$inactivos = DB::table('document')
    ->where('is_active', '=', 0)
    ->orWhere('is_active', '=', DB::raw("b'0'"))
    ->count();
echo "❌ Registros inactivos (is_active=0): {$inactivos}\n\n";

// Mostrar estructura de la tabla
echo "📋 Estructura de la tabla 'document':\n";
$columns = DB::select("DESCRIBE document");
foreach ($columns as $column) {
    echo "   - {$column->Field} ({$column->Type})\n";
}

echo "\n";

// Mostrar algunos ejemplos
if ($total > 0) {
    echo "📄 Ejemplos de registros (primeros 3):\n";
    $ejemplos = DB::table('document')->limit(3)->get();
    foreach ($ejemplos as $i => $doc) {
        echo "   " . ($i + 1) . ". ID: {$doc->document_id}, Código: {$doc->prefix_code}-{$doc->code}, Archivo: {$doc->file_name}, Activo: " . ($doc->is_active ? 'SÍ' : 'NO') . "\n";
    }
    echo "\n";
}

// Verificar si ya hay documentos en qr_files
$qrFilesCount = DB::table('qr_files')->count();
echo "📦 Documentos en 'qr_files': {$qrFilesCount}\n";

if ($qrFilesCount > 0) {
    $conDocumentId = DB::table('qr_files')->whereNotNull('document_id')->count();
    echo "   - Con document_id (migrados): {$conDocumentId}\n";
    $sinDocumentId = DB::table('qr_files')->whereNull('document_id')->count();
    echo "   - Sin document_id (nuevos): {$sinDocumentId}\n";
}

echo "\n✅ Verificación completada.\n\n";

