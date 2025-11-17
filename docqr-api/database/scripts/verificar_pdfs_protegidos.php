<?php

/**
 * Script para verificar si hay PDFs protegidos con contraseña
 * y cómo manejarlos
 */

$rootDir = realpath(__DIR__ . '/../..');
$autoloadPath = $rootDir . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
require $autoloadPath;

$bootstrapPath = $rootDir . DIRECTORY_SEPARATOR . 'bootstrap' . DIRECTORY_SEPARATOR . 'app.php';
$app = require_once $bootstrapPath;
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

echo "\n=== VERIFICAR PDFs PROTEGIDOS CON CONTRASEÑA ===\n\n";

// Verificar algunos documentos antiguos con password_file
$documentos = DB::table('document')
    ->where('is_file_name_encript', 1)
    ->orWhereNotNull('password_file')
    ->limit(10)
    ->get();

echo "📋 Documentos con password_file o encriptados: " . $documentos->count() . "\n\n";

foreach ($documentos as $doc) {
    echo "📄 Documento ID: {$doc->document_id}\n";
    echo "   Código: {$doc->code}\n";
    echo "   Archivo: {$doc->file_name}\n";
    echo "   password_file: {$doc->password_file}\n";
    echo "   is_file_name_encript: {$doc->is_file_name_encript}\n";
    
    // Buscar el archivo físico
    $archivoEncontrado = null;
    if ($doc->prefix_code) {
        $tipoPath = "uploads/{$doc->prefix_code}";
        if (Storage::disk('local')->exists($tipoPath)) {
            $archivos = Storage::disk('local')->allFiles($tipoPath);
            
            // Buscar por password_file
            if ($doc->password_file) {
                foreach ($archivos as $archivo) {
                    if (strpos(basename($archivo), $doc->password_file) !== false) {
                        $archivoEncontrado = $archivo;
                        break;
                    }
                }
            }
        }
    }
    
    if ($archivoEncontrado) {
        $rutaCompleta = Storage::disk('local')->path($archivoEncontrado);
        echo "   📁 Archivo: {$archivoEncontrado}\n";
        
        // Intentar leer el PDF para verificar si está protegido
        try {
            $pdf = new \setasign\Fpdi\Tcpdf\Fpdi();
            // FPDI puede manejar PDFs protegidos si se proporciona la contraseña
            // Por ahora intentamos sin contraseña
            $pageCount = @$pdf->setSourceFile($rutaCompleta);
            
            if ($pageCount > 0) {
                echo "   ✅ PDF accesible (sin contraseña o contraseña no requerida)\n";
            } else {
                echo "   ⚠️  PDF puede estar protegido o corrupto\n";
            }
        } catch (\Exception $e) {
            $errorMsg = $e->getMessage();
            if (stripos($errorMsg, 'password') !== false || 
                stripos($errorMsg, 'encrypted') !== false ||
                stripos($errorMsg, 'protected') !== false) {
                echo "   🔒 PDF PROTEGIDO CON CONTRASEÑA: {$errorMsg}\n";
            } else {
                echo "   ❌ Error al leer PDF: {$errorMsg}\n";
            }
        }
    } else {
        echo "   ❌ Archivo no encontrado\n";
    }
    echo "\n";
}

echo "💡 NOTA: El campo 'password_file' parece ser un identificador único del archivo,\n";
echo "   no una contraseña de protección del PDF.\n";
echo "   Si un PDF está protegido, FPDI puede manejarlo si se proporciona la contraseña.\n\n";

