<?php

namespace App\Http\Controllers;

use App\Models\QrFile;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

/**
 * Controlador para servir archivos (PDFs y QRs) de forma segura
 * 
 * Este controlador sirve los archivos a través de la API para mantener
 * un flujo escalable y compatible con almacenamiento en la nube
 */
class FileController extends Controller
{
    /**
     * Servir PDF original o final
     * 
     * @param string $qrId ID del QR
     * @return Response
     */
    public function servePdf(string $qrId): Response
    {
        try {
            $qrFile = QrFile::where('qr_id', $qrId)->firstOrFail();

            // Determinar qué archivo servir
            if ($qrFile->final_path) {
                // PDF final con QR embebido
                $filePath = str_replace('final/', '', $qrFile->final_path);
                $disk = 'final';
                $fullPath = Storage::disk($disk)->path($filePath);
            } elseif ($qrFile->file_path) {
                // PDF original
                $fullPath = Storage::disk('local')->path($qrFile->file_path);
            } else {
                abort(404, 'Archivo PDF no encontrado');
            }

            // Verificar que el archivo existe
            if (!file_exists($fullPath)) {
                abort(404, 'Archivo PDF no encontrado');
            }

            // Leer el contenido del archivo
            $content = file_get_contents($fullPath);

            // Sanitizar nombre de archivo para seguridad
            $safeFilename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $qrFile->original_filename);
            
            // Retornar respuesta con headers apropiados para PDF
            $response = response($content, 200)
                ->header('Content-Type', 'application/pdf')
                ->header('Content-Disposition', 'inline; filename="' . $safeFilename . '"')
                ->header('Content-Length', strlen($content))
                ->header('Cache-Control', 'public, max-age=3600')
                ->header('X-Content-Type-Options', 'nosniff');
            
            // Solo agregar X-Frame-Options en producción (en desarrollo permite iframe desde localhost)
            if (app()->environment('production')) {
                $response->header('X-Frame-Options', 'SAMEORIGIN');
            }
            
            return $response;

        } catch (\Exception $e) {
            Log::error('Error al servir PDF: ' . $e->getMessage());
            abort(404, 'Error al cargar el documento');
        }
    }

    /**
     * Servir imagen QR
     * 
     * @param string $qrId ID del QR
     * @return Response
     */
    public function serveQr(string $qrId): Response
    {
        try {
            $qrFile = QrFile::where('qr_id', $qrId)->firstOrFail();

            if (!$qrFile->qr_path) {
                abort(404, 'Código QR no encontrado');
            }

            // Obtener la ruta completa del QR
            $qrFilename = basename($qrFile->qr_path);
            $fullPath = Storage::disk('qrcodes')->path($qrFilename);

            // Verificar que el archivo existe
            if (!file_exists($fullPath)) {
                abort(404, 'Código QR no encontrado');
            }

            // Leer el contenido del archivo
            $content = file_get_contents($fullPath);

            // Sanitizar nombre de archivo para seguridad
            $safeFilename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $qrFilename);
            
            // Retornar respuesta con headers apropiados para PNG
            return response($content, 200)
                ->header('Content-Type', 'image/png')
                ->header('Content-Disposition', 'inline; filename="' . $safeFilename . '"')
                ->header('Content-Length', strlen($content))
                ->header('Cache-Control', 'public, max-age=3600')
                ->header('X-Content-Type-Options', 'nosniff')
                ->header('X-Frame-Options', 'SAMEORIGIN');

        } catch (\Exception $e) {
            Log::error('Error al servir QR: ' . $e->getMessage());
            abort(404, 'Error al cargar el código QR');
        }
    }
}

