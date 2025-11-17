<?php

/**
 * Script para buscar un documento específico en la BD y verificar si el archivo existe
 */

$rootDir = realpath(__DIR__ . '/../..');
$autoloadPath = $rootDir . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
require $autoloadPath;

$bootstrapPath = $rootDir . DIRECTORY_SEPARATOR . 'bootstrap' . DIRECTORY_SEPARATOR . 'app.php';
$app = require_once $bootstrapPath;
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

$nombreArchivo = 'F-9-InfN°257-25CO12COMV03R.pdf';
$codigo = 'N° 257-25 CO12';

echo "\n=== BUSCAR DOCUMENTO ===\n\n";
echo "Buscando: {$nombreArchivo}\n";
echo "Código: {$codigo}\n\n";

// Buscar en BD
$doc = DB::table('qr_files')
    ->where('original_filename', 'like', '%257-25CO12%')
    ->orWhere('original_filename', 'like', '%F-9-Inf%257%')
    ->first();

if ($doc) {
    echo "✅ ENCONTRADO EN BD:\n";
    echo "   qr_id: {$doc->qr_id}\n";
    echo "   file_path: {$doc->file_path}\n";
    echo "   folder_name: {$doc->folder_name}\n";
    
    // Verificar si el archivo existe
    if ($doc->file_path && Storage::disk('local')->exists($doc->file_path)) {
        echo "   ✅ Archivo físico EXISTE\n";
    } else {
        echo "   ❌ Archivo físico NO EXISTE en: {$doc->file_path}\n";
        
        // Buscar el archivo
        echo "\n   🔍 Buscando archivo...\n";
        $archivos = Storage::disk('local')->allFiles('uploads/IN');
        $encontrados = [];
        foreach ($archivos as $archivo) {
            if (strpos(basename($archivo), '257-25CO12') !== false || 
                strpos(basename($archivo), '257') !== false) {
                $encontrados[] = $archivo;
            }
        }
        
        if (count($encontrados) > 0) {
            echo "   📁 Archivos encontrados con '257':\n";
            foreach ($encontrados as $arch) {
                echo "      - {$arch}\n";
            }
        } else {
            echo "   ❌ No se encontraron archivos con '257'\n";
        }
    }
} else {
    echo "❌ NO ENCONTRADO EN BD\n";
    
    // Buscar en tabla antigua
    if (DB::getSchemaBuilder()->hasTable('document')) {
        $docAntiguo = DB::table('document')
            ->where('file_name', 'like', '%257-25CO12%')
            ->orWhere('code', 'like', '%257-25%')
            ->first();
        
        if ($docAntiguo) {
            echo "\n✅ ENCONTRADO EN TABLA ANTIGUA (document):\n";
            echo "   document_id: {$docAntiguo->document_id}\n";
            echo "   code: {$docAntiguo->code}\n";
            echo "   file_name: {$docAntiguo->file_name}\n";
            echo "   prefix_code: {$docAntiguo->prefix_code}\n";
        }
    }
}

echo "\n";

