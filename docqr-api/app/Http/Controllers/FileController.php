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
     * @param Request $request Request HTTP para validar ETag
     * @return Response
     */
    public function servePdf(string $qrId, Request $request): Response
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
            
            // Estrategia de caché según tipo de PDF y ambiente
            $isProduction = app()->environment('production');
            $isFinalPdf = (bool) $qrFile->final_path;
            
            if ($isFinalPdf) {
                // PDF final: NO cachear (puede cambiar al reposicionar QR)
                // En producción también sin caché para garantizar versión actualizada
                // Agregar timestamp en la URL para forzar recarga
                $cacheControl = 'no-cache, no-store, must-revalidate, private';
                $pragma = 'no-cache';
                $expires = '0';
            } else {
                // PDF original: Cachear según ambiente
                if ($isProduction) {
                    // Producción: Cachear más tiempo (archivos estables)
                    $cacheControl = 'public, max-age=86400, immutable'; // 24 horas
                    $pragma = null;
                    $expires = null;
                } else {
                    // Desarrollo: Cachear menos tiempo (para ver cambios)
                    $cacheControl = 'public, max-age=300'; // 5 minutos
                    $pragma = 'no-cache';
                    $expires = '0';
                }
            }
            
            // Generar ETag para validación de caché (útil en producción)
            $etag = md5($fullPath . filemtime($fullPath));
            
            // Retornar respuesta con headers apropiados para PDF
            $response = response($content, 200)
                ->header('Content-Type', 'application/pdf')
                ->header('Content-Disposition', 'inline; filename="' . $safeFilename . '"')
                ->header('Content-Length', strlen($content))
                ->header('Cache-Control', $cacheControl)
                ->header('ETag', $etag)
                ->header('X-Content-Type-Options', 'nosniff')
                ->header('X-Content-Security-Policy', "default-src 'self'");
            
            // Headers adicionales según tipo de caché
            if ($pragma) {
                $response->header('Pragma', $pragma);
            }
            if ($expires) {
                $response->header('Expires', $expires);
            }
            
            // Headers de seguridad en producción
            if ($isProduction) {
                $response->header('X-Frame-Options', 'SAMEORIGIN')
                         ->header('X-XSS-Protection', '1; mode=block')
                         ->header('Referrer-Policy', 'strict-origin-when-cross-origin');
            }
            
            // Validar ETag del cliente (304 Not Modified si no cambió)
            if ($request->header('If-None-Match') === $etag) {
                return response('', 304)
                    ->header('ETag', $etag)
                    ->header('Cache-Control', $cacheControl);
            }
            
            return $response;

        } catch (\Exception $e) {
            Log::error('Error al servir PDF: ' . $e->getMessage());
            abort(404, 'Error al cargar el documento');
        }
    }

    /**
     * Servir PDF original (siempre el original, nunca el final)
     * Útil para el editor que necesita trabajar con el PDF sin QR
     * 
     * @param string $qrId ID del QR
     * @return Response
     */
    public function serveOriginalPdf(string $qrId, Request $request): Response
    {
        try {
            $qrFile = QrFile::where('qr_id', $qrId)->firstOrFail();

            // SIEMPRE servir el PDF original, nunca el final
            if (!$qrFile->file_path) {
                abort(404, 'Archivo PDF original no encontrado');
            }

            $fullPath = Storage::disk('local')->path($qrFile->file_path);

            // IMPORTANTE: El PDF original NO debe eliminarse para permitir reposicionamiento
            // Si no existe, es un error (no usar PDF final como fallback en el editor)
            if (!file_exists($fullPath)) {
                Log::error('PDF original no encontrado - no se debe usar PDF final como fallback en el editor', [
                    'qr_id' => $qrId,
                    'file_path' => $qrFile->file_path,
                    'final_path' => $qrFile->final_path
                ]);
                abort(404, 'Archivo PDF original no encontrado. El editor requiere el PDF original sin QR.');
            }

            // Leer el contenido del archivo
            $content = file_get_contents($fullPath);

            // Sanitizar nombre de archivo para seguridad
            $safeFilename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $qrFile->original_filename);
            
            // Estrategia de caché para PDF original
            // Si viene del editor (parámetro editor=true), NO cachear para ver cambios inmediatos
            $isFromEditor = $request->has('editor') && $request->input('editor') === 'true';
            $isProduction = app()->environment('production');
            
            if ($isFromEditor) {
                // Desde el editor: NO cachear para ver cambios inmediatos
                $cacheControl = 'no-cache, no-store, must-revalidate, private';
                $pragma = 'no-cache';
                $expires = '0';
            } elseif ($isProduction) {
                // Producción: Cachear más tiempo
                $cacheControl = 'public, max-age=86400, immutable'; // 24 horas
                $pragma = null;
                $expires = null;
            } else {
                // Desarrollo: Cachear menos tiempo
                $cacheControl = 'public, max-age=300'; // 5 minutos
                $pragma = 'no-cache';
                $expires = '0';
            }
            
            // Generar ETag
            $etag = md5($fullPath . (file_exists($fullPath) ? filemtime($fullPath) : 0));
            
            // Retornar respuesta con headers apropiados para PDF
            $response = response($content, 200)
                ->header('Content-Type', 'application/pdf')
                ->header('Content-Disposition', 'inline; filename="' . $safeFilename . '"')
                ->header('Content-Length', strlen($content))
                ->header('Cache-Control', $cacheControl)
                ->header('ETag', $etag)
                ->header('X-Content-Type-Options', 'nosniff');
            
            if ($pragma) {
                $response->header('Pragma', $pragma);
            }
            if ($expires) {
                $response->header('Expires', $expires);
            }
            
            // Headers de seguridad en producción
            if ($isProduction) {
                $response->header('X-Frame-Options', 'SAMEORIGIN')
                         ->header('X-XSS-Protection', '1; mode=block')
                         ->header('Referrer-Policy', 'strict-origin-when-cross-origin');
            }
            
            // Validar ETag del cliente
            if ($request->header('If-None-Match') === $etag) {
                return response('', 304)
                    ->header('ETag', $etag)
                    ->header('Cache-Control', $cacheControl);
            }
            
            return $response;

        } catch (\Exception $e) {
            Log::error('Error al servir PDF original: ' . $e->getMessage());
            abort(404, 'Error al cargar el documento original');
        }
    }

    /**
     * Servir imagen QR
     * 
     * @param string $qrId ID del QR
     * @return Response
     */
    public function serveQr(string $qrId, Request $request): Response
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
            
            // Estrategia de caché para imágenes QR (estables)
            $isProduction = app()->environment('production');
            $cacheControl = $isProduction 
                ? 'public, max-age=86400, immutable' // 24 horas en producción
                : 'public, max-age=3600'; // 1 hora en desarrollo
            
            // Generar ETag
            $etag = md5($fullPath . filemtime($fullPath));
            
            // Retornar respuesta con headers apropiados para PNG
            $response = response($content, 200)
                ->header('Content-Type', 'image/png')
                ->header('Content-Disposition', 'inline; filename="' . $safeFilename . '"')
                ->header('Content-Length', strlen($content))
                ->header('Cache-Control', $cacheControl)
                ->header('ETag', $etag)
                ->header('X-Content-Type-Options', 'nosniff');
            
            // Headers de seguridad en producción
            if ($isProduction) {
                $response->header('X-Frame-Options', 'SAMEORIGIN')
                         ->header('X-XSS-Protection', '1; mode=block');
            }
            
            // Validar ETag del cliente
            if ($request->header('If-None-Match') === $etag) {
                return response('', 304)
                    ->header('ETag', $etag)
                    ->header('Cache-Control', $cacheControl);
            }
            
            return $response;

        } catch (\Exception $e) {
            Log::error('Error al servir QR: ' . $e->getMessage());
            abort(404, 'Error al cargar el código QR');
        }
    }
}

