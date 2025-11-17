<?php

/**
 * Script para ver en detalle los documentos duplicados
 */

$rootDir = realpath(__DIR__ . '/../..');
$autoloadPath = $rootDir . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
require $autoloadPath;

$bootstrapPath = $rootDir . DIRECTORY_SEPARATOR . 'bootstrap' . DIRECTORY_SEPARATOR . 'app.php';
$app = require_once $bootstrapPath;
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Models\QrFile;

echo "\n=== DETALLE DE DOCUMENTOS DUPLICADOS ===\n\n";

// Encontrar document_id duplicados
$duplicados = DB::table('qr_files')
    ->select('document_id', DB::raw('count(*) as cantidad'))
    ->whereNotNull('document_id')
    ->groupBy('document_id')
    ->having('cantidad', '>', 1)
    ->orderBy('cantidad', 'desc')
    ->get();

if ($duplicados->count() === 0) {
    echo "✅ No hay duplicados\n\n";
    exit;
}

echo "Se encontraron " . $duplicados->count() . " document_id con duplicados:\n\n";

$totalDuplicados = 0;

foreach ($duplicados as $dup) {
    echo "📋 document_id {$dup->document_id} ({$dup->cantidad} registros):\n";
    
    $registros = QrFile::where('document_id', $dup->document_id)
        ->orderBy('created_at', 'asc')
        ->get(['id', 'qr_id', 'original_filename', 'folder_name', 'file_path', 'status', 'created_at']);
    
    foreach ($registros as $idx => $reg) {
        $fecha = $reg->created_at ? $reg->created_at->format('Y-m-d H:i:s') : 'N/A';
        $marcador = $idx === 0 ? '✅ (MANTENER - más antiguo)' : '❌ (DUPLICADO - eliminar)';
        echo "   " . ($idx + 1) . ". ID: {$reg->id}, QR: {$reg->qr_id}, Archivo: {$reg->original_filename}, Status: {$reg->status}, Creado: {$fecha} {$marcador}\n";
    }
    
    $totalDuplicados += ($dup->cantidad - 1); // Restar 1 porque uno se mantiene
    echo "\n";
}

echo "=== RESUMEN ===\n";
echo "Total de document_id duplicados: " . $duplicados->count() . "\n";
echo "Total de registros a eliminar: {$totalDuplicados}\n";
echo "Después de limpiar, quedarán: " . (QrFile::count() - $totalDuplicados) . " documentos\n\n";

