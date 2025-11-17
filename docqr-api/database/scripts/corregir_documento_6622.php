<?php

$rootDir = realpath(__DIR__ . '/../..');
$autoloadPath = $rootDir . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
require $autoloadPath;

$bootstrapPath = $rootDir . DIRECTORY_SEPARATOR . 'bootstrap' . DIRECTORY_SEPARATOR . 'app.php';
$app = require_once $bootstrapPath;
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

echo "\n=== CORREGIR DOCUMENTO 6622 (RECIENTE - SEPTIEMBRE 2025) ===\n\n";

// Buscar el documento 6622
$docAntiguo = DB::table('document')->where('document_id', 6622)->first();

if (!$docAntiguo) {
    echo "❌ Documento 6622 no encontrado en tabla antigua\n";
    exit(1);
}

echo "📋 Datos del documento:\n";
echo "   Código: {$docAntiguo->code}\n";
echo "   Archivo: {$docAntiguo->file_name}\n";
echo "   password_file: {$docAntiguo->password_file}\n";
echo "   Fecha: {$docAntiguo->creation_date}\n\n";

// Buscar en qr_files
$docNuevo = DB::table('qr_files')->where('document_id', 6622)->first();

if (!$docNuevo) {
    echo "❌ Documento no encontrado en qr_files\n";
    exit(1);
}

echo "📋 Estado actual en qr_files:\n";
echo "   qr_id: {$docNuevo->qr_id}\n";
echo "   file_path actual: {$docNuevo->file_path}\n\n";

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
    
    foreach ($archivos as $archivo) {
        if (strpos($archivo, $codigoLimpio) !== false || strpos($archivo, $codigoSinEspacios) !== false) {
            $archivoEncontrado = $archivo;
            echo "   ✅ ENCONTRADO: {$archivo}\n";
            break;
        }
    }
}

if ($archivoEncontrado && Storage::disk('local')->exists($archivoEncontrado)) {
    // Actualizar en BD
    DB::table('qr_files')
        ->where('qr_id', $docNuevo->qr_id)
        ->update(['file_path' => $archivoEncontrado]);
    
    echo "\n✅ ACTUALIZADO EN BD\n";
    echo "   Nuevo file_path: {$archivoEncontrado}\n";
} else {
    echo "\n❌ Archivo físico NO encontrado\n";
    echo "   El archivo puede no haberse copiado o no existir\n";
}

echo "\n";

