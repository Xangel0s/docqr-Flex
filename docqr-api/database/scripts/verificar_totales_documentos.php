<?php

/**
 * Script para verificar los totales de documentos entre el sistema antiguo y el nuevo
 */

$rootDir = realpath(__DIR__ . '/../..');
$autoloadPath = $rootDir . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
require $autoloadPath;

$bootstrapPath = $rootDir . DIRECTORY_SEPARATOR . 'bootstrap' . DIRECTORY_SEPARATOR . 'app.php';
$app = require_once $bootstrapPath;
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Models\QrFile;

echo "\n=== VERIFICACIÓN DE TOTALES DE DOCUMENTOS ===\n\n";

// 1. Contar documentos en tabla antigua (document)
$totalAntiguo = 0;
$totalAntiguoActivos = 0;
$totalAntiguoInactivos = 0;

if (DB::getSchemaBuilder()->hasTable('document')) {
    $totalAntiguo = DB::table('document')->count();
    
    // Contar activos e inactivos
    $totalAntiguoActivos = DB::table('document')
        ->where(function($query) {
            $query->where('is_active', '=', 1)
                  ->orWhere('is_active', '=', DB::raw("b'1'"))
                  ->orWhere('is_active', '=', true);
        })
        ->count();
    
    $totalAntiguoInactivos = $totalAntiguo - $totalAntiguoActivos;
    
    echo "📊 TABLA ANTIGUA (document):\n";
    echo "   Total: {$totalAntiguo}\n";
    echo "   Activos: {$totalAntiguoActivos}\n";
    echo "   Inactivos: {$totalAntiguoInactivos}\n\n";
} else {
    echo "⚠️  La tabla 'document' no existe\n\n";
}

// 2. Contar documentos en tabla nueva (qr_files)
$totalNuevo = QrFile::count();
$totalMigrados = QrFile::whereNotNull('document_id')->count();
$totalNuevos = QrFile::whereNull('document_id')->count();

echo "📊 TABLA NUEVA (qr_files):\n";
echo "   Total: {$totalNuevo}\n";
echo "   Migrados (con document_id): {$totalMigrados}\n";
echo "   Nuevos (sin document_id): {$totalNuevos}\n\n";

// 3. Calcular diferencia
$diferencia = $totalNuevo - $totalAntiguoActivos;
echo "📈 DIFERENCIA:\n";
echo "   Sistema nuevo: {$totalNuevo}\n";
echo "   Sistema antiguo (activos): {$totalAntiguoActivos}\n";
echo "   Diferencia: {$diferencia} " . ($diferencia > 0 ? "MÁS" : "MENOS") . "\n\n";

// 4. Verificar duplicados por document_id
if ($totalAntiguoActivos > 0) {
    echo "🔍 VERIFICANDO DUPLICADOS...\n";
    
    $duplicados = DB::table('qr_files')
        ->select('document_id', DB::raw('count(*) as cantidad'))
        ->whereNotNull('document_id')
        ->groupBy('document_id')
        ->having('cantidad', '>', 1)
        ->get();
    
    if ($duplicados->count() > 0) {
        echo "   ⚠️  Se encontraron " . $duplicados->count() . " document_id duplicados:\n";
        foreach ($duplicados as $dup) {
            echo "      - document_id {$dup->document_id}: {$dup->cantidad} registros\n";
        }
        echo "\n";
    } else {
        echo "   ✅ No hay duplicados por document_id\n\n";
    }
}

// 5. Verificar documentos nuevos (sin document_id)
if ($totalNuevos > 0) {
    echo "📋 DOCUMENTOS NUEVOS (sin document_id):\n";
    $nuevosDocs = QrFile::whereNull('document_id')
        ->orderBy('created_at', 'desc')
        ->limit(20)
        ->get(['id', 'qr_id', 'original_filename', 'folder_name', 'created_at']);
    
    echo "   Total: {$totalNuevos}\n";
    echo "   Últimos 20:\n";
    foreach ($nuevosDocs as $doc) {
        $fecha = $doc->created_at ? $doc->created_at->format('Y-m-d H:i:s') : 'N/A';
        echo "      - {$doc->original_filename} ({$doc->folder_name}) - {$fecha}\n";
    }
    echo "\n";
}

// 6. Verificar documentos antiguos que NO se migraron
if ($totalAntiguoActivos > 0) {
    echo "🔍 VERIFICANDO DOCUMENTOS ANTIGUOS NO MIGRADOS...\n";
    
    $documentosAntiguos = DB::table('document')
        ->where(function($query) {
            $query->where('is_active', '=', 1)
                  ->orWhere('is_active', '=', DB::raw("b'1'"))
                  ->orWhere('is_active', '=', true);
        })
        ->pluck('document_id');
    
    $documentosMigrados = QrFile::whereNotNull('document_id')
        ->pluck('document_id')
        ->toArray();
    
    $noMigrados = $documentosAntiguos->diff($documentosMigrados);
    
    if ($noMigrados->count() > 0) {
        echo "   ⚠️  Hay " . $noMigrados->count() . " documentos activos que NO se migraron:\n";
        foreach ($noMigrados->take(10) as $docId) {
            echo "      - document_id: {$docId}\n";
        }
        if ($noMigrados->count() > 10) {
            echo "      ... y " . ($noMigrados->count() - 10) . " más\n";
        }
        echo "\n";
    } else {
        echo "   ✅ Todos los documentos activos fueron migrados\n\n";
    }
}

// 7. Resumen final
echo "=== RESUMEN ===\n";
echo "Sistema antiguo (activos): {$totalAntiguoActivos}\n";
echo "Sistema nuevo (total): {$totalNuevo}\n";
echo "   - Migrados: {$totalMigrados}\n";
echo "   - Nuevos: {$totalNuevos}\n";
echo "Diferencia: {$diferencia} " . ($diferencia > 0 ? "MÁS en el nuevo sistema" : "MENOS en el nuevo sistema") . "\n\n";

if ($diferencia > 0) {
    echo "💡 POSIBLES CAUSAS DE LA DIFERENCIA:\n";
    echo "   1. Se crearon {$totalNuevos} documentos nuevos después de la migración\n";
    echo "   2. Puede haber duplicados en la migración\n";
    echo "   3. Puede haber documentos de prueba\n";
    echo "\n";
}

