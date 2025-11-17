<?php

/**
 * Script para corregir file_path de documentos RECIENTES (últimos 6 meses)
 * Los documentos antiguos se dejan como están
 */

$rootDir = realpath(__DIR__ . '/../..');
$autoloadPath = $rootDir . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
require $autoloadPath;

$bootstrapPath = $rootDir . DIRECTORY_SEPARATOR . 'bootstrap' . DIRECTORY_SEPARATOR . 'app.php';
$app = require_once $bootstrapPath;
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use App\Models\QrFile;

echo "\n=== CORREGIR DOCUMENTOS RECIENTES (ÚLTIMOS 6 MESES) ===\n\n";

// Buscar documentos recientes con file_path incorrecto
$fechaMinima = now()->subMonths(6)->format('Y-m-d');

$documentos = QrFile::whereHas('document', function($query) use ($fechaMinima) {
        $query->where('creation_date', '>=', $fechaMinima);
    })
    ->orWhere(function($query) use ($fechaMinima) {
        $query->where('created_at', '>=', $fechaMinima)
              ->where(function($q) {
                  $q->where('file_path', 'like', 'uploads/migrado_%')
                    ->orWhere(function($q2) {
                        $q2->whereNotNull('file_path')
                           ->where('file_path', 'not like', 'uploads/%/%/%');
                    });
              });
    })
    ->get()
    ->filter(function($doc) {
        // Filtrar solo los que realmente no existen
        if (!$doc->file_path) return true;
        return !Storage::disk('local')->exists($doc->file_path);
    });

echo "📋 Encontrados " . $documentos->count() . " documentos RECIENTES con file_path incorrecto\n\n";

$corregidos = 0;
$noEncontrados = 0;

foreach ($documentos as $doc) {
    echo "🔄 Buscando archivo para: {$doc->original_filename} (qr_id: {$doc->qr_id})\n";
    
    // Obtener datos de tabla antigua
    $docAntiguo = null;
    if ($doc->document_id) {
        $docAntiguo = DB::table('document')->where('document_id', $doc->document_id)->first();
    }
    
    if (!$docAntiguo) {
        echo "   ⚠️  No se encontró en tabla antigua, omitiendo...\n\n";
        continue;
    }
    
    // Verificar que sea reciente
    $fechaDoc = $docAntiguo->creation_date ?? $doc->created_at;
    if ($fechaDoc < $fechaMinima) {
        echo "   ⏭️  Documento antiguo (fecha: {$fechaDoc}), omitiendo...\n\n";
        continue;
    }
    
    // Buscar archivo
    $archivoEncontrado = null;
    $prefixCode = $docAntiguo->prefix_code ?? null;
    $code = $docAntiguo->code ?? null;
    $passwordFile = $docAntiguo->password_file ?? null;
    
    if ($prefixCode) {
        $tipoPath = "uploads/{$prefixCode}";
        if (Storage::disk('local')->exists($tipoPath)) {
            $archivos = Storage::disk('local')->allFiles($tipoPath);
            
            // Buscar por password_file (más confiable)
            if ($passwordFile) {
                foreach ($archivos as $archivo) {
                    if (strpos(basename($archivo), $passwordFile) !== false) {
                        $archivoEncontrado = $archivo;
                        break;
                    }
                }
            }
            
            // Si no se encontró, buscar por código
            if (!$archivoEncontrado && $code) {
                $codigoLimpio = trim($code);
                $codigoSinEspacios = preg_replace('/\s+/', '', $codigoLimpio);
                foreach ($archivos as $archivo) {
                    if (strpos($archivo, $codigoLimpio) !== false || 
                        strpos($archivo, $codigoSinEspacios) !== false) {
                        $archivoEncontrado = $archivo;
                        break;
                    }
                }
            }
        }
    }
    
    if ($archivoEncontrado && Storage::disk('local')->exists($archivoEncontrado)) {
        $doc->file_path = $archivoEncontrado;
        $doc->save();
        echo "   ✅ Corregido: {$archivoEncontrado}\n";
        $corregidos++;
    } else {
        echo "   ❌ No encontrado\n";
        $noEncontrados++;
    }
    echo "\n";
}

echo "=== RESUMEN ===\n";
echo "✅ Corregidos: {$corregidos}\n";
echo "❌ No encontrados: {$noEncontrados}\n";
echo "\n💡 Los documentos antiguos se mantienen como están.\n\n";

