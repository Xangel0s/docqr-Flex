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

            if ($qrFile->final_path) {
                $filePath = str_replace('final/', '', $qrFile->final_path);
                $disk = 'final';
                $fullPath = Storage::disk($disk)->path($filePath);
            } elseif ($qrFile->file_path) {
                $fullPath = Storage::disk('local')->path($qrFile->file_path);
            } else {
                abort(404, 'Archivo PDF no encontrado');
            }

            if (!file_exists($fullPath)) {
                abort(404, 'Archivo PDF no encontrado');
            }

            $content = file_get_contents($fullPath);
            $safeFilename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $qrFile->original_filename);
            
            $isProduction = app()->environment('production');
            $isFinalPdf = (bool) $qrFile->final_path;
            
            if ($isFinalPdf) {
                $cacheControl = 'no-cache, no-store, must-revalidate, private';
                $pragma = 'no-cache';
                $expires = '0';
            } else {
                if ($isProduction) {
                    $cacheControl = 'public, max-age=86400, immutable';
                    $pragma = null;
                    $expires = null;
                } else {
                    $cacheControl = 'public, max-age=300';
                    $pragma = 'no-cache';
                    $expires = '0';
                }
            }
            
            $etag = md5($fullPath . filemtime($fullPath));
            
            $response = response($content, 200)
                ->header('Content-Type', 'application/pdf')
                ->header('Content-Disposition', 'inline; filename="' . $safeFilename . '"')
                ->header('Content-Length', strlen($content))
                ->header('Cache-Control', $cacheControl)
                ->header('ETag', $etag)
                ->header('X-Content-Type-Options', 'nosniff')
                ->header('X-Content-Security-Policy', "default-src 'self'");
            
            if ($pragma) {
                $response->header('Pragma', $pragma);
            }
            if ($expires) {
                $response->header('Expires', $expires);
            }
            
            if ($isProduction) {
                $response->header('X-Frame-Options', 'SAMEORIGIN')
                         ->header('X-XSS-Protection', '1; mode=block')
                         ->header('Referrer-Policy', 'strict-origin-when-cross-origin');
            }
            
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

            if (!$qrFile->file_path) {
                if ($request->expectsJson() || $request->wantsJson() || str_starts_with($request->path(), 'api/')) {
                    return response()->json([
                        'success' => false,
                        'message' => 'El documento no tiene file_path configurado en la base de datos',
                        'qr_id' => $qrId
                    ], 404);
                }
                abort(404, 'Archivo PDF original no encontrado');
            }

            $fullPath = Storage::disk('local')->path($qrFile->file_path);

            if (!file_exists($fullPath)) {
                $possiblePaths = [
                    $fullPath,
                    storage_path('app/' . $qrFile->file_path),
                    base_path('storage/app/' . $qrFile->file_path),
                    str_replace('uploads/', 'storage/app/uploads/', $qrFile->file_path),
                ];
                
                $foundPath = null;
                foreach ($possiblePaths as $possiblePath) {
                    if (file_exists($possiblePath) && is_file($possiblePath)) {
                        $foundPath = $possiblePath;
                        break;
                    }
                }
                
                if (!$foundPath) {
                    if ($request->expectsJson() || $request->wantsJson() || str_starts_with($request->path(), 'api/')) {
                        return response()->json([
                            'success' => false,
                            'message' => 'El archivo PDF original no existe físicamente en el servidor. El editor requiere el PDF original sin QR.',
                            'qr_id' => $qrId,
                            'file_path' => $qrFile->file_path,
                            'full_path' => $fullPath,
                            'storage_exists' => Storage::disk('local')->exists($qrFile->file_path)
                        ], 404);
                    }
                    abort(404, 'Archivo PDF original no encontrado. El editor requiere el PDF original sin QR.');
                } else {
                    $fullPath = $foundPath;
                }
            }

            $content = file_get_contents($fullPath);
            $safeFilename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $qrFile->original_filename);
            
            $isFromEditor = $request->has('editor') && $request->input('editor') === 'true';
            $isProduction = app()->environment('production');
            
            if ($isFromEditor) {
                $cacheControl = 'no-cache, no-store, must-revalidate, private';
                $pragma = 'no-cache';
                $expires = '0';
            } elseif ($isProduction) {
                $cacheControl = 'public, max-age=86400, immutable';
                $pragma = null;
                $expires = null;
            } else {
                $cacheControl = 'public, max-age=300';
                $pragma = 'no-cache';
                $expires = '0';
            }
            
            $etag = md5($fullPath . (file_exists($fullPath) ? filemtime($fullPath) : 0));
            
            $response = response($content, 200)
                ->header('Content-Type', 'application/pdf')
                ->header('Content-Disposition', 'inline; filename="' . $safeFilename . '"')
                ->header('Content-Length', strlen($content))
                ->header('Cache-Control', $cacheControl)
                ->header('ETag', $etag)
                ->header('X-Content-Type-Options', 'nosniff')
                ->header('X-Accel-Buffering', 'no');
            
            $origin = $request->header('Origin');
            if ($origin) {
                $response->header('Access-Control-Allow-Origin', $origin);
                $response->header('Access-Control-Allow-Methods', 'GET, HEAD, OPTIONS');
                $response->header('Access-Control-Allow-Headers', 'Content-Type, Accept, X-Requested-With, Authorization');
                $response->header('Access-Control-Expose-Headers', 'Content-Type, Content-Length, Content-Disposition');
            }
            
            if (str_contains($request->header('Host', ''), 'ngrok') || 
                str_contains($request->header('X-Forwarded-Host', ''), 'ngrok')) {
                $response->header('X-Frame-Options', 'ALLOWALL');
            }
            
            if ($pragma) {
                $response->header('Pragma', $pragma);
            }
            if ($expires) {
                $response->header('Expires', $expires);
            }
            
            if ($isProduction) {
                $response->header('X-Frame-Options', 'SAMEORIGIN')
                         ->header('X-XSS-Protection', '1; mode=block')
                         ->header('Referrer-Policy', 'strict-origin-when-cross-origin');
            }
            
            if ($request->header('If-None-Match') === $etag) {
                return response('', 304)
                    ->header('ETag', $etag)
                    ->header('Cache-Control', $cacheControl);
            }
            
            return $response;

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            Log::warning('PDF original no encontrado (documento no existe en BD):', [
                'qr_id' => $qrId,
                'error' => $e->getMessage()
            ]);
            // Devolver JSON en lugar de HTML para peticiones de API
            if ($request->expectsJson() || $request->wantsJson() || str_starts_with($request->path(), 'api/')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Documento no encontrado',
                    'qr_id' => $qrId
                ], 404);
            }
            abort(404, 'Archivo PDF original no encontrado');
        } catch (\Exception $e) {
            Log::error('Error al servir PDF original: ' . $e->getMessage(), [
                'qr_id' => $qrId,
                'trace' => $e->getTraceAsString()
            ]);
            // Devolver JSON en lugar de HTML para peticiones de API
            if ($request->expectsJson() || $request->wantsJson() || str_starts_with($request->path(), 'api/')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Error al cargar el documento original: ' . $e->getMessage(),
                    'qr_id' => $qrId
                ], 500);
            }
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

