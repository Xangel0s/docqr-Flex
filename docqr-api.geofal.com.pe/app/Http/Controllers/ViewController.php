<?php

namespace App\Http\Controllers;

use App\Models\QrFile;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

/**
 * Controlador para visualizar PDFs con QR
 * 
 * Esta ruta incrementa el contador de escaneos cada vez que se accede
 */
class ViewController extends Controller
{
    /**
     * Visualizar PDF final con QR (incrementa contador de escaneos)
     * 
     * @param string $hash QR ID del documento
     * @return Response
     */
    public function view(string $hash): Response
    {
        try {
            // Validar qr_id contra inyección SQL
            if (!\App\Helpers\QrIdValidator::isValid($hash)) {
                abort(400, 'ID de documento inválido');
            }
            
            // Buscar el documento por qr_id
            $qrFile = QrFile::where('qr_id', $hash)->first();

            if (!$qrFile) {
                abort(404, 'Documento no encontrado');
            }

            // Incrementar contador de escaneos SOLO cuando se accede al documento a través del QR
            // Esto valida que el documento fue escaneado correctamente
            $qrFile->incrementScanCount();

            // Determinar qué archivo servir usando helper compartido
            // Priorización: final_path > file_path > rutas alternativas
            $pdfInfo = \App\Helpers\PdfPathHelper::getPdfPathToServe($qrFile);
            
            if (!$pdfInfo) {
                Log::error('No se encontró archivo PDF para el documento:', [
                    'qr_id' => $hash,
                    'final_path' => $qrFile->final_path,
                    'file_path' => $qrFile->file_path
                ]);
                abort(404, 'Archivo PDF no encontrado');
            }
            
            $filePath = $pdfInfo['filePath'];
            $disk = $pdfInfo['disk'];
            $fullPath = $pdfInfo['fullPath'];
            $pdfType = $pdfInfo['type'];
            

            // Leer el contenido del archivo
            $content = file_get_contents($fullPath);

            // Retornar respuesta con headers apropiados para PDF
            // Headers de seguridad básicos (SecurityHeaders middleware maneja los demás)
            // Cache-Control: no-store para evitar problemas de caché en clientes
            $response = response($content, 200)
                ->header('Content-Type', 'application/pdf')
                ->header('Content-Disposition', 'inline; filename="' . $qrFile->original_filename . '"')
                ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
                ->header('Pragma', 'no-cache')
                ->header('Expires', '0')
                ->header('X-Content-Type-Options', 'nosniff');
            
            // Headers de seguridad para PDFs embebidos
            $frontendUrl = env('FRONTEND_URL', 'https://docqr.geofal.com.pe');
            $response->header('X-Frame-Options', 'SAMEORIGIN');
            $response->header('Content-Security-Policy', "frame-ancestors 'self' {$frontendUrl};");
            
            return $response;

        } catch (\Exception $e) {
            Log::error('Error al visualizar PDF: ' . $e->getMessage());
            abort(500, 'Error al cargar el documento');
        }
    }
}

