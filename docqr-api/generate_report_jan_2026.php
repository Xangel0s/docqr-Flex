<?php
/**
 * Script de Producción para generar Reporte de Documentos (Estilo Inacal)
 * Periodo: 01 de Enero al 29 de Enero de 2026
 */

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\QrFile;
use Illuminate\Support\Facades\DB;

$startDate = '2026-01-01 00:00:00';
$endDate   = '2026-01-29 23:59:59';
$outputFile = 'Reporte_Inacal_Enero_2026.csv';

echo "Generando reporte de documentos del $startDate al $endDate...\n";

// Abrir archivo CSV para escritura
$fp = fopen($outputFile, 'w');

// Escribir cabecera (con BOM para Excel si es necesario, pero usaremos el separador ;)
// fputs($fp, $bom =( chr(0xEF) . chr(0xBB) . chr(0xBF) ));
$header = ["TIPO DE INFORME", "NOMBRE DEL PDF", "CODIGO DEL ENSAYO", "LINK ENVIADO A INACAL", "LINK REDICCIONADO", "FECHA DE INFORME"];
fputcsv($fp, $header, ";");

// Obtener registros de qr_files en el rango de fecha
// Unimos con la tabla document para obtener más información si está disponible
$qrFiles = QrFile::whereBetween('created_at', [$startDate, $endDate])
    ->with('document')
    ->orderBy('created_at', 'desc')
    ->get();

$count = 0;
foreach ($qrFiles as $qrFile) {
    // 1. Determinar tipo (Fallback a Informe de Ensayo)
    $tipo = "Informe de Ensayo";
    
    // 2. Nombre del PDF
    $nombrePdf = $qrFile->original_filename ?: ($qrFile->document ? $qrFile->document->file_name : 'No disponible');
    
    // 3. Código del ensayo (folder_name limpio)
    $codigoEnsayo = $qrFile->folder_name;
    
    // 4. Link enviado a INACAL (El que el usuario pide en linksverificar.text)
    // El sistema redirige automáticamente mediante ViewController
    $linkBase = "https://docqr.geofal.com.pe/auth/login-download-file/";
    $linkInacal = $linkBase . $qrFile->folder_name;
    
    // 5. Link Redireccionado (El visor real con el hash qr_id)
    $linkRedireccionado = "https://docqr-api.geofal.com.pe/api/view/" . $qrFile->qr_id;
    
    // 6. Fecha
    $fecha = $qrFile->created_at->format('d/m/Y H:i:s');
    
    fputcsv($fp, [
        $tipo,
        $nombrePdf,
        $codigoEnsayo,
        $linkInacal,
        $linkRedireccionado,
        $fecha
    ], ";");
    
    $count++;
}

fclose($fp);

echo "¡Reporte generado con éxito! ($count registros)\n";
echo "Archivo: " . realpath($outputFile) . "\n";
