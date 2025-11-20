<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\UploadController;
use App\Http\Controllers\EmbedController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\ViewController;
use App\Http\Controllers\SystemController;
use App\Http\Controllers\CompressionController;
use App\Http\Controllers\FileController;
use App\Http\Controllers\AuthController;
use App\Http\Middleware\AuthMiddleware;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Aquí se definen las rutas de la API REST para el sistema DocQR.
| Todas las rutas están prefijadas con /api
|
*/

// Rutas públicas (sin autenticación)
Route::post('/auth/login', [AuthController::class, 'login']);
Route::get('/view/{hash}', [ViewController::class, 'view']); // Vista pública de PDF con QR
Route::get('/files/pdf/{qrId}', [FileController::class, 'servePdf']); // PDF final (si existe) o original
Route::get('/files/pdf-original/{qrId}', [FileController::class, 'serveOriginalPdf']); // Siempre PDF original
Route::get('/files/qr/{qrId}', [FileController::class, 'serveQr']); // Imagen QR

// Las peticiones OPTIONS ahora son manejadas por HandleCorsOptions middleware
// Esta ruta es redundante pero se mantiene como respaldo

// Rutas protegidas (requieren autenticación)
Route::middleware([AuthMiddleware::class])->group(function () {
    // Autenticación (con rate limiting más estricto)
    Route::get('/auth/me', [AuthController::class, 'me'])->middleware('throttle:120,1');
    Route::post('/auth/logout', [AuthController::class, 'logout'])->middleware('throttle:30,1');
    Route::put('/auth/profile', [AuthController::class, 'updateProfile'])->middleware('throttle:60,1');
    Route::put('/auth/password', [AuthController::class, 'updatePassword'])->middleware('throttle:10,1');
    
    // Ruta para subir PDF y generar QR
    Route::post('/upload', [UploadController::class, 'upload']);

    // Ruta para embebir QR en PDF con posición
    Route::put('/embed', [EmbedController::class, 'embed']);

    // Ruta para recibir PDF modificado con pdf-lib (método iLovePDF)
    Route::put('/embed-pdf', [EmbedController::class, 'embedPdf']);

    // Rutas para gestión de documentos (con rate limiting)
    Route::get('/documents', [DocumentController::class, 'index'])->middleware('throttle:120,1');
    Route::get('/documents/stats', [DocumentController::class, 'stats'])->middleware('throttle:60,1');
    Route::post('/documents/create', [DocumentController::class, 'create']); // Crear documento sin PDF
    Route::get('/documents/qr/{qrId}', [DocumentController::class, 'showByQrId']);
    Route::get('/documents/{id}', [DocumentController::class, 'show']);
    Route::put('/documents/qr/{qrId}/folder-name', [DocumentController::class, 'updateFolderName']);
    Route::post('/documents/qr/{qrId}/regenerate-qr', [DocumentController::class, 'regenerateQr']); // Regenerar QR con URL actualizada
    Route::post('/documents/qr/{qrId}/attach-pdf', [DocumentController::class, 'attachPdf']); // Adjuntar PDF sin procesar
    Route::delete('/documents/{id}', [DocumentController::class, 'destroy']);

    // Endpoint de prueba para diagnosticar URLs (TEMPORAL - eliminar en producción)
    Route::get('/test-url', function (\Illuminate\Http\Request $request) {
    return response()->json([
        'FRONTEND_URL_env' => env('FRONTEND_URL'),
        'FRONTEND_URL_config' => config('app.frontend_url', 'NO CONFIGURADO'),
        'request_host' => $request->getHost(),
        'request_scheme' => $request->getScheme(),
        'request_secure' => $request->secure(),
        'x_forwarded_proto' => $request->header('X-Forwarded-Proto'),
        'x_forwarded_host' => $request->header('X-Forwarded-Host'),
        'x_frontend_origin' => $request->header('X-Frontend-Origin'),
        'x_forwarded_for' => $request->header('X-Forwarded-For'),
        'https' => $request->server('HTTPS'),
        'url_helper_test' => \App\Helpers\UrlHelper::url('/api/view/test123', $request),
        'laravel_url' => url('/api/view/test123'),
        'full_url' => $request->fullUrl(),
        'origin' => $request->header('Origin'),
    ]);
    });

    // Endpoint de diagnóstico para verificar archivos PDF (TEMPORAL - eliminar en producción)
    Route::get('/diagnose-pdf/{qrId}', function (string $qrId) {
    try {
        $qrFile = \App\Models\QrFile::where('qr_id', $qrId)->firstOrFail();
        
        $filePath = $qrFile->file_path;
        $fullPath = \Illuminate\Support\Facades\Storage::disk('local')->path($filePath);
        $storageRoot = \Illuminate\Support\Facades\Storage::disk('local')->path('');
        
        // Verificar si existe usando Storage
        $storageExists = \Illuminate\Support\Facades\Storage::disk('local')->exists($filePath);
        
        // Verificar si existe físicamente
        $fileExists = file_exists($fullPath);
        $isFile = is_file($fullPath);
        $isReadable = is_readable($fullPath);
        $fileSize = $fileExists ? filesize($fullPath) : null;
        
        // Listar archivos en el directorio si existe
        $directoryPath = dirname($fullPath);
        $directoryExists = is_dir($directoryPath);
        $filesInDirectory = [];
        if ($directoryExists) {
            $filesInDirectory = array_slice(scandir($directoryPath), 2); // Excluir . y ..
        }
        
        return response()->json([
            'success' => true,
            'qr_id' => $qrId,
            'document_id' => $qrFile->id,
            'file_path_db' => $filePath,
            'original_filename' => $qrFile->original_filename,
            'storage_root' => $storageRoot,
            'full_path' => $fullPath,
            'directory_path' => $directoryPath,
            'storage_exists' => $storageExists,
            'file_exists' => $fileExists,
            'is_file' => $isFile,
            'is_readable' => $isReadable,
            'file_size' => $fileSize,
            'directory_exists' => $directoryExists,
            'files_in_directory' => $filesInDirectory,
            'final_path' => $qrFile->final_path,
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ], 500);
    }
    });

    // Rutas del sistema
    Route::get('/system/compression-status', [SystemController::class, 'compressionStatus']);

    // Rutas de compresión manual
    Route::get('/compression/list', [CompressionController::class, 'listCompressible']);
    Route::post('/compression/compress', [CompressionController::class, 'compressByMonth']);
    Route::get('/compression/download', [CompressionController::class, 'downloadZip']);
});

