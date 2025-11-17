<?php

/**
 * Script para eliminar documentos duplicados de la migración
 * Mantiene el registro más antiguo de cada document_id
 */

$rootDir = realpath(__DIR__ . '/../..');
$autoloadPath = $rootDir . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
require $autoloadPath;

$bootstrapPath = $rootDir . DIRECTORY_SEPARATOR . 'bootstrap' . DIRECTORY_SEPARATOR . 'app.php';
$app = require_once $bootstrapPath;
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Models\QrFile;
use Illuminate\Support\Facades\Storage;

echo "\n=== ELIMINACIÓN DE DOCUMENTOS DUPLICADOS ===\n\n";

// Encontrar document_id duplicados
$duplicados = DB::table('qr_files')
    ->select('document_id', DB::raw('count(*) as cantidad'))
    ->whereNotNull('document_id')
    ->groupBy('document_id')
    ->having('cantidad', '>', 1)
    ->orderBy('cantidad', 'desc')
    ->get();

if ($duplicados->count() === 0) {
    echo "✅ No hay duplicados para eliminar\n\n";
    exit;
}

echo "Se encontraron " . $duplicados->count() . " document_id con duplicados\n";
echo "Se eliminarán los registros más recientes, manteniendo el más antiguo de cada uno.\n\n";

$totalEliminados = 0;
$archivosEliminados = 0;
$errores = 0;

foreach ($duplicados as $dup) {
    echo "📋 Procesando document_id {$dup->document_id} ({$dup->cantidad} registros)...\n";
    
    // Obtener todos los registros de este document_id, ordenados por fecha (más antiguo primero)
    $registros = QrFile::where('document_id', $dup->document_id)
        ->orderBy('created_at', 'asc')
        ->get();
    
    // El primero (más antiguo) se mantiene
    $mantener = $registros->first();
    echo "   ✅ Manteniendo: ID {$mantener->id} (creado: {$mantener->created_at})\n";
    
    // Los demás se eliminan
    $aEliminar = $registros->skip(1);
    
    foreach ($aEliminar as $reg) {
        try {
            // Eliminar archivos físicos asociados (solo si no son compartidos)
            // Verificar si el archivo es único o compartido
            $filePath = $reg->file_path;
            $qrPath = $reg->qr_path;
            $finalPath = $reg->final_path;
            
            // Eliminar QR si existe y no es compartido con el registro que se mantiene
            if ($qrPath && $qrPath !== $mantener->qr_path) {
                $qrFilename = basename($qrPath);
                if (Storage::disk('qrcodes')->exists($qrFilename)) {
                    Storage::disk('qrcodes')->delete($qrFilename);
                    echo "      🗑️  QR eliminado: {$qrFilename}\n";
                    $archivosEliminados++;
                }
            }
            
            // Eliminar PDF final si existe y no es compartido
            if ($finalPath && $finalPath !== $mantener->final_path) {
                $finalPathRel = str_replace('final/', '', $finalPath);
                if (Storage::disk('final')->exists($finalPathRel)) {
                    Storage::disk('final')->delete($finalPathRel);
                    echo "      🗑️  PDF final eliminado: {$finalPathRel}\n";
                    $archivosEliminados++;
                }
            }
            
            // NO eliminar el PDF original porque puede ser compartido
            // El PDF original se mantiene para el registro que se conserva
            
            // Eliminar registro de la BD
            $reg->forceDelete();
            echo "      ✅ Registro eliminado: ID {$reg->id}\n";
            $totalEliminados++;
            
        } catch (\Exception $e) {
            echo "      ❌ Error al eliminar ID {$reg->id}: " . $e->getMessage() . "\n";
            $errores++;
        }
    }
    
    echo "\n";
}

echo "=== RESUMEN ===\n";
echo "✅ Registros eliminados: {$totalEliminados}\n";
echo "🗑️  Archivos físicos eliminados: {$archivosEliminados}\n";
if ($errores > 0) {
    echo "❌ Errores: {$errores}\n";
}

// Verificar totales finales
$totalFinal = QrFile::count();
$totalMigrados = QrFile::whereNotNull('document_id')->count();
$totalNuevos = QrFile::whereNull('document_id')->count();

echo "\n📊 TOTALES FINALES:\n";
echo "   Total documentos: {$totalFinal}\n";
echo "   Migrados: {$totalMigrados}\n";
echo "   Nuevos: {$totalNuevos}\n";

// Verificar si quedan duplicados
$duplicadosRestantes = DB::table('qr_files')
    ->select('document_id', DB::raw('count(*) as cantidad'))
    ->whereNotNull('document_id')
    ->groupBy('document_id')
    ->having('cantidad', '>', 1)
    ->count();

if ($duplicadosRestantes === 0) {
    echo "\n✅ No quedan duplicados\n";
} else {
    echo "\n⚠️  Aún quedan {$duplicadosRestantes} document_id con duplicados\n";
}

echo "\n";

