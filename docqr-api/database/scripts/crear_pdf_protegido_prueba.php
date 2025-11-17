<?php

/**
 * Script para crear un PDF protegido con contraseña para pruebas
 */

$rootDir = realpath(__DIR__ . '/../..');
$autoloadPath = $rootDir . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
require $autoloadPath;

$bootstrapPath = $rootDir . DIRECTORY_SEPARATOR . 'bootstrap' . DIRECTORY_SEPARATOR . 'app.php';
$app = require_once $bootstrapPath;
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Storage;

echo "\n=== CREAR PDF PROTEGIDO PARA PRUEBAS ===\n\n";

// Crear un PDF simple con TCPDF y protegerlo con contraseña
try {
    $pdf = new \TCPDF();
    $pdf->SetCreator('Geofal - Sistema de Pruebas');
    $pdf->SetAuthor('Geofal');
    $pdf->SetTitle('PDF Protegido - Prueba');
    $pdf->SetSubject('Prueba de PDF con contraseña');
    
    // Agregar una página
    $pdf->AddPage();
    
    // Agregar contenido
    $pdf->SetFont('helvetica', 'B', 16);
    $pdf->Cell(0, 10, 'PDF PROTEGIDO CON CONTRASEÑA', 0, 1, 'C');
    $pdf->Ln(10);
    
    $pdf->SetFont('helvetica', '', 12);
    $pdf->MultiCell(0, 10, 'Este es un PDF de prueba protegido con contraseña.', 0, 'L');
    $pdf->Ln(5);
    $pdf->MultiCell(0, 10, 'Contraseña: prueba123', 0, 'L');
    $pdf->Ln(10);
    $pdf->MultiCell(0, 10, 'Este PDF se usa para validar el flujo de usuario cuando un documento requiere contraseña.', 0, 'L');
    
    // Proteger el PDF con contraseña
    // Contraseña de usuario: "prueba123" (para abrir el PDF)
    // Contraseña de propietario: "" (vacía, sin restricciones adicionales)
    $pdf->SetProtection(
        ['print', 'copy'],  // Permisos permitidos
        'prueba123',        // Contraseña de usuario (para abrir)
        '',                 // Contraseña de propietario (vacía)
        0,                  // Modo de cifrado (0 = RC4 40bit, 1 = RC4 128bit)
        []                  // Permisos adicionales
    );
    
    // Guardar el PDF protegido
    $nombreArchivo = 'pdf_protegido_prueba.pdf';
    $rutaCompleta = Storage::disk('local')->path('uploads/' . $nombreArchivo);
    
    // Asegurar que el directorio existe
    $directorio = dirname($rutaCompleta);
    if (!is_dir($directorio)) {
        mkdir($directorio, 0755, true);
    }
    
    // Guardar el PDF
    $pdf->Output($rutaCompleta, 'F');
    
    echo "✅ PDF protegido creado exitosamente\n";
    echo "   📁 Ubicación: storage/app/uploads/{$nombreArchivo}\n";
    echo "   🔒 Contraseña: prueba123\n";
    echo "\n";
    echo "💡 Para probar el flujo:\n";
    echo "   1. Sube este PDF al sistema\n";
    echo "   2. El sistema detectará que está protegido\n";
    echo "   3. Para visualizar: PDF.js pedirá la contraseña automáticamente\n";
    echo "   4. Para embebir QR: Necesitarás desbloquear el PDF primero\n";
    echo "\n";
    
} catch (\Exception $e) {
    echo "❌ Error al crear PDF protegido: " . $e->getMessage() . "\n";
    echo "   Trace: " . $e->getTraceAsString() . "\n\n";
}

