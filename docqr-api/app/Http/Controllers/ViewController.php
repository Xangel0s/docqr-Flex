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
            // Buscar el documento por qr_id
            $qrFile = QrFile::where('qr_id', $hash)->first();

            if (!$qrFile) {
                abort(404, 'Documento no encontrado');
            }

            // Incrementar contador de escaneos SOLO cuando se accede al documento a través del QR
            // Esto valida que el documento fue escaneado correctamente
            $qrFile->incrementScanCount();

            // Determinar qué archivo servir
            // Si existe el PDF final con QR, servir ese, sino el original
            if ($qrFile->final_path) {
                // PDF final: final/CE/filename.pdf -> CE/filename.pdf
                $filePath = str_replace('final/', '', $qrFile->final_path);
                $disk = 'final';
                $fullPath = Storage::disk($disk)->path($filePath);
            } else {
                // PDF original: uploads/CE/CE-12345/filename.pdf
                $filePath = $qrFile->file_path;
                $disk = 'local';
                $fullPath = Storage::disk($disk)->path($filePath);
            }

            // Verificar que el archivo existe
            if (!file_exists($fullPath)) {
                abort(404, 'Archivo no encontrado');
            }

            // Leer el contenido del archivo
            $content = file_get_contents($fullPath);

            // Retornar respuesta con headers apropiados para PDF
            return response($content, 200)
                ->header('Content-Type', 'application/pdf')
                ->header('Content-Disposition', 'inline; filename="' . $qrFile->original_filename . '"')
                ->header('Cache-Control', 'public, max-age=3600');

        } catch (\Exception $e) {
            Log::error('Error al visualizar PDF: ' . $e->getMessage());
            abort(500, 'Error al cargar el documento');
        }
    }
}

