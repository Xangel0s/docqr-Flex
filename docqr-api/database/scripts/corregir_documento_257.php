<?php

/**
 * Script para corregir específicamente el documento F-9-InfN°257-25CO12COMV03R.pdf
 */

$rootDir = realpath(__DIR__ . '/../..');
$autoloadPath = $rootDir . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
require $autoloadPath;

$bootstrapPath = $rootDir . DIRECTORY_SEPARATOR . 'bootstrap' . DIRECTORY_SEPARATOR . 'app.php';
$app = require_once $bootstrapPath;
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

echo "\n=== CORREGIR DOCUMENTO 257-25CO12 ===\n\n";

// Buscar el documento
$doc = DB::table('qr_files')
    ->where('original_filename', 'like', '%257-25CO12%')
    ->orWhere('original_filename', 'like', '%F-9-Inf%257%')
    ->first();

if (!$doc) {
    echo "❌ Documento no encontrado en qr_files\n";
    exit(1);
}

echo "✅ Documento encontrado:\n";
echo "   qr_id: {$doc->qr_id}\n";
echo "   file_path actual: {$doc->file_path}\n";
echo "   original_filename: {$doc->original_filename}\n\n";

// Buscar en tabla antigua
$docAntiguo = DB::table('document')->where('document_id', $doc->document_id)->first();

if ($docAntiguo) {
    echo "📋 Datos de tabla antigua:\n";
    echo "   code: {$docAntiguo->code}\n";
    echo "   password_file: {$docAntiguo->password_file}\n";
    echo "   prefix_code: {$docAntiguo->prefix_code}\n\n";
    
    // Buscar archivo por password_file
    echo "🔍 Buscando archivo por password_file: {$docAntiguo->password_file}\n";
    $archivos = Storage::disk('local')->allFiles('uploads/IN');
    
    $archivoEncontrado = null;
    foreach ($archivos as $archivo) {
        $nombreArchivo = basename($archivo);
        if (strpos($nombreArchivo, $docAntiguo->password_file) !== false) {
            $archivoEncontrado = $archivo;
            echo "   ✅ ENCONTRADO: {$archivo}\n";
            break;
        }
    }
    
    if (!$archivoEncontrado) {
        // Buscar por código en la ruta
        echo "\n🔍 Buscando por código en la ruta: {$docAntiguo->code}\n";
        $codigoLimpio = trim($docAntiguo->code);
        $codigoSinEspacios = preg_replace('/\s+/', '', $codigoLimpio);
        $codigoSinEspeciales = preg_replace('/[^a-zA-Z0-9\-_\.]/', '', $codigoLimpio);
        
        $codigosBuscar = array_unique([$codigoLimpio, $codigoSinEspacios, $codigoSinEspeciales]);
        
        foreach ($codigosBuscar as $codigoVariante) {
            if (empty($codigoVariante)) continue;
            foreach ($archivos as $archivo) {
                if (strpos($archivo, $codigoVariante) !== false) {
                    $archivoEncontrado = $archivo;
                    echo "   ✅ ENCONTRADO: {$archivo}\n";
                    break 2;
                }
            }
        }
    }
    
    if ($archivoEncontrado && Storage::disk('local')->exists($archivoEncontrado)) {
        // Actualizar en BD
        DB::table('qr_files')
            ->where('qr_id', $doc->qr_id)
            ->update(['file_path' => $archivoEncontrado]);
        
        echo "\n✅ ACTUALIZADO EN BD\n";
        echo "   Nuevo file_path: {$archivoEncontrado}\n";
    } else {
        echo "\n❌ Archivo físico NO encontrado\n";
    }
} else {
    echo "❌ No se encontró en tabla antigua\n";
}

echo "\n";

