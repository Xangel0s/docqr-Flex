<?php

namespace App\Http\Controllers;

use App\Models\QrFile;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;
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
     * @return Response|JsonResponse
     */
    public function servePdf(string $qrId, Request $request): Response|JsonResponse
    {
        try {
            if (!\App\Helpers\QrIdValidator::isValid($qrId)) {
                return response()->json([
                    'success' => false,
                    'message' => 'ID de documento inválido'
                ], 400);
            }
            
            $qrFile = QrFile::where('qr_id', $qrId)->firstOrFail();

            $pdfInfo = \App\Helpers\PdfPathHelper::getPdfPathToServe($qrFile);
            
            if (!$pdfInfo) {
                if ($request->expectsJson() || $request->wantsJson() || str_starts_with($request->path(), 'api/')) {
                    return response()->json([
                        'success' => false,
                        'message' => 'El documento no tiene archivo PDF configurado',
                        'qr_id' => $qrId
                    ], 404)->header('Content-Type', 'application/json');
                }
                abort(404, 'Archivo PDF no encontrado');
            }
            
            $filePath = $pdfInfo['filePath'];
            $disk = $pdfInfo['disk'];
            $fullPath = $pdfInfo['fullPath'];
            $isFinalPdf = ($pdfInfo['type'] === 'final');

            $content = file_get_contents($fullPath);
            $safeFilename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $qrFile->original_filename);
            
            $isProduction = app()->environment('production');
            
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
            
            $response = response($content, 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="' . $safeFilename . '"',
                'Content-Length' => (string) strlen($content),
                'Cache-Control' => $cacheControl,
                'ETag' => $etag,
                'X-Content-Type-Options' => 'nosniff',
                'X-Content-Security-Policy' => "default-src 'self'",
            ]);
            
            $origin = $request->header('Origin');
            $allowedOrigins = config('cors.allowed_origins', []);
            if (is_callable($allowedOrigins)) {
                $allowedOrigins = $allowedOrigins();
            }
            $isOriginAllowed = in_array($origin, $allowedOrigins) || 
                              in_array('*', $allowedOrigins) || 
                              app()->environment('local');
            
            if ($origin && $isOriginAllowed) {
                $response->header('Access-Control-Allow-Origin', $origin);
                $response->header('Access-Control-Allow-Methods', 'GET, HEAD, OPTIONS');
                $response->header('Access-Control-Allow-Headers', 'Content-Type, Accept, Authorization, X-Requested-With, X-Frontend-Origin');
                $response->header('Access-Control-Expose-Headers', 'Content-Type, Content-Length, Content-Disposition, ETag');
                $response->header('Access-Control-Allow-Credentials', 'true');
            }
            
            if ($pragma) {
                $response->header('Pragma', $pragma);
            }
            if ($expires) {
                $response->header('Expires', $expires);
            }
            
            if ($request->header('If-None-Match') === $etag) {
                return response('', 304)
                    ->header('ETag', $etag)
                    ->header('Cache-Control', $cacheControl);
            }
            
            return $response;

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            Log::error('PDF no encontrado (documento no existe en BD):', [
                'qr_id' => $qrId,
                'error' => $e->getMessage()
            ]);
            if ($request->expectsJson() || $request->wantsJson() || str_starts_with($request->path(), 'api/')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Documento no encontrado',
                    'qr_id' => $qrId
                ], 404)->header('Content-Type', 'application/json');
            }
            abort(404, 'Archivo PDF no encontrado');
        } catch (\Exception $e) {
            Log::error('Error al servir PDF: ' . $e->getMessage(), [
                'qr_id' => $qrId,
                'trace' => $e->getTraceAsString()
            ]);
            // Devolver JSON en lugar de HTML para peticiones de API
            if ($request->expectsJson() || $request->wantsJson() || str_starts_with($request->path(), 'api/')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Error al cargar el documento: ' . $e->getMessage(),
                    'qr_id' => $qrId
                ], 500)->header('Content-Type', 'application/json');
            }
            abort(404, 'Error al cargar el documento');
        }
    }

    /**
     * Servir PDF original (siempre el original, nunca el final)
     * Útil para el editor que necesita trabajar con el PDF sin QR
     * 
     * @param string $qrId ID del QR
     * @return Response|JsonResponse
     */
    public function serveOriginalPdf(string $qrId, Request $request): Response|JsonResponse
    {
        try {
            if (!\App\Helpers\QrIdValidator::isValid($qrId)) {
                return response()->json([
                    'success' => false,
                    'message' => 'ID de documento inválido'
                ], 400);
            }
            
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
                ];
                
                if (str_contains($qrFile->file_path, 'uploads/')) {
                    $pathWithoutUploads = str_replace('uploads/', '', $qrFile->file_path);
                    $possiblePaths[] = storage_path('app/uploads/' . $pathWithoutUploads);
                    $possiblePaths[] = base_path('storage/app/uploads/' . $pathWithoutUploads);
                    $possiblePaths[] = public_path('uploads/' . $pathWithoutUploads);
                }
                
                if ($qrFile->original_filename) {
                    $filename = basename($qrFile->original_filename);
                    $possiblePaths[] = storage_path('app/uploads/' . $filename);
                    $possiblePaths[] = base_path('storage/app/uploads/' . $filename);
                    $possiblePaths[] = public_path('uploads/' . $filename);
                    
                    $uploadsDir = storage_path('app/uploads');
                    if (is_dir($uploadsDir)) {
                        $iterator = new \RecursiveIteratorIterator(
                            new \RecursiveDirectoryIterator($uploadsDir, \RecursiveDirectoryIterator::SKIP_DOTS),
                            \RecursiveIteratorIterator::SELF_FIRST
                        );
                        foreach ($iterator as $file) {
                            if ($file->isFile() && $file->getFilename() === $filename) {
                                $possiblePaths[] = $file->getPathname();
                                break;
                            }
                        }
                    }
                }
                
                $foundPath = null;
                foreach ($possiblePaths as $possiblePath) {
                    if ($possiblePath && file_exists($possiblePath) && is_file($possiblePath)) {
                        $foundPath = $possiblePath;
                        Log::info('PDF original encontrado en ruta alternativa:', [
                            'qr_id' => $qrId,
                            'ruta_original' => $fullPath,
                            'ruta_encontrada' => $foundPath
                        ]);
                        break;
                    }
                }
                
                if (!$foundPath) {
                    Log::error('PDF original no encontrado físicamente después de buscar en todas las ubicaciones:', [
                        'qr_id' => $qrId,
                        'file_path' => $qrFile->file_path,
                        'original_filename' => $qrFile->original_filename,
                        'full_path' => $fullPath,
                        'storage_exists' => Storage::disk('local')->exists($qrFile->file_path),
                        'rutas_buscadas' => array_filter($possiblePaths)
                    ]);
                    
                    if ($request->expectsJson() || $request->wantsJson() || str_starts_with($request->path(), 'api/')) {
                        return response()->json([
                            'success' => false,
                            'message' => 'El archivo PDF original no existe físicamente en el servidor. El editor requiere el PDF original sin QR.',
                            'qr_id' => $qrId,
                            'file_path' => $qrFile->file_path,
                            'original_filename' => $qrFile->original_filename,
                            'full_path' => $fullPath,
                            'storage_exists' => Storage::disk('local')->exists($qrFile->file_path)
                        ], 404)->header('Content-Type', 'application/json');
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
            
            $response = response($content, 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="' . $safeFilename . '"',
                'Content-Length' => (string) strlen($content),
                'Cache-Control' => $cacheControl,
                'ETag' => $etag,
                'X-Content-Type-Options' => 'nosniff',
                'X-Accel-Buffering' => 'no',
            ]);
            
            $origin = $request->header('Origin');
            $allowedOrigins = config('cors.allowed_origins', []);
            if (is_callable($allowedOrigins)) {
                $allowedOrigins = $allowedOrigins();
            }
            $isOriginAllowed = in_array($origin, $allowedOrigins) || 
                              in_array('*', $allowedOrigins) || 
                              app()->environment('local');
            
            if ($origin && $isOriginAllowed) {
                $response->header('Access-Control-Allow-Origin', $origin);
                $response->header('Access-Control-Allow-Methods', 'GET, HEAD, OPTIONS');
                $response->header('Access-Control-Allow-Headers', 'Content-Type, Accept, X-Requested-With, Authorization, X-Frontend-Origin');
                $response->header('Access-Control-Expose-Headers', 'Content-Type, Content-Length, Content-Disposition, ETag');
                $response->header('Access-Control-Allow-Credentials', 'true');
            }
            
            if ($pragma) {
                $response->header('Pragma', $pragma);
            }
            if ($expires) {
                $response->header('Expires', $expires);
            }
            
            if ($request->header('If-None-Match') === $etag) {
                return response('', 304)
                    ->header('ETag', $etag)
                    ->header('Cache-Control', $cacheControl);
            }
            
            return $response;

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            Log::error('PDF original no encontrado (documento no existe en BD):', [
                'qr_id' => $qrId,
                'error' => $e->getMessage()
            ]);
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
            if (!\App\Helpers\QrIdValidator::isValid($qrId)) {
                abort(400, 'ID de documento inválido');
            }
            
            $qrFile = QrFile::where('qr_id', $qrId)->firstOrFail();

            if (!$qrFile->qr_path) {
                abort(404, 'Código QR no encontrado');
            }

            $qrFilename = basename($qrFile->qr_path);
            $fullPath = Storage::disk('qrcodes')->path($qrFilename);

            if (!file_exists($fullPath)) {
                abort(404, 'Código QR no encontrado');
            }

            $content = file_get_contents($fullPath);
            $safeFilename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $qrFilename);
            
            $isProduction = app()->environment('production');
            $cacheControl = $isProduction 
                ? 'public, max-age=86400, immutable'
                : 'public, max-age=3600';
            
            $etag = md5($fullPath . filemtime($fullPath));
            
            $response = response($content, 200)
                ->header('Content-Type', 'image/png')
                ->header('Content-Disposition', 'inline; filename="' . $safeFilename . '"')
                ->header('Content-Length', strlen($content))
                ->header('Cache-Control', $cacheControl)
                ->header('ETag', $etag)
                ->header('X-Content-Type-Options', 'nosniff');
            
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

