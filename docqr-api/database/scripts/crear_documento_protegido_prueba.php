<?php

/**
 * Script para crear un documento de prueba con PDF protegido en la BD
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
use App\Services\QrGeneratorService;

echo "\n=== CREAR DOCUMENTO CON PDF PROTEGIDO PARA PRUEBAS ===\n\n";

// Verificar que el PDF protegido existe
$pdfPath = 'uploads/pdf_protegido_prueba.pdf';
if (!Storage::disk('local')->exists($pdfPath)) {
    echo "❌ ERROR: El PDF protegido no existe en: {$pdfPath}\n";
    echo "   Ejecuta primero: php database/scripts/crear_pdf_protegido_prueba.php\n\n";
    exit(1);
}

echo "✅ PDF protegido encontrado\n";

// Generar qr_id único
$qrId = bin2hex(random_bytes(16));

// Generar QR code (con URL de prueba)
$qrGenerator = app(QrGeneratorService::class);
$qrUrl = \App\Helpers\UrlHelper::url("/api/view/{$qrId}", request());
$qrPath = $qrGenerator->generate($qrUrl, $qrId);

// Obtener tamaño del archivo
$fileSize = Storage::disk('local')->size($pdfPath);

// Crear documento en la BD
$qrFile = QrFile::create([
    'qr_id' => $qrId,
    'document_id' => null, // No es de la tabla antigua
    'folder_name' => 'PRUEBA-PDF-PROTEGIDO',
    'original_filename' => 'pdf_protegido_prueba.pdf',
    'file_path' => $pdfPath,
    'qr_path' => $qrPath,
    'final_path' => null,
    'file_size' => $fileSize,
    'qr_position' => null,
    'status' => 'uploaded', // Estado inicial
    'scan_count' => 0,
    'last_scanned_at' => null,
]);

echo "✅ Documento creado en la BD\n";
echo "   qr_id: {$qrId}\n";
echo "   folder_name: PRUEBA-PDF-PROTEGIDO\n";
echo "   file_path: {$pdfPath}\n";
echo "\n";
echo "📋 INFORMACIÓN PARA PROBAR:\n";
echo "   🔒 Contraseña del PDF: prueba123\n";
echo "   📄 Nombre: pdf_protegido_prueba.pdf\n";
echo "   📁 Carpeta: PRUEBA-PDF-PROTEGIDO\n";
echo "\n";
echo "🧪 FLUJO DE PRUEBA:\n";
echo "   1. Ve a 'Mis Documentos' en el sistema\n";
echo "   2. Busca el documento 'pdf_protegido_prueba.pdf'\n";
echo "   3. Haz clic para visualizarlo\n";
echo "   4. El visor PDF.js pedirá la contraseña: prueba123\n";
echo "   5. Intenta embebir el QR - debería mostrar error de PDF protegido\n";
echo "\n";

