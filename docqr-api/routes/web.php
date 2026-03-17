<?php

use Illuminate\Support\Facades\Route;

// Health Check Endpoint
Route::get('/up', function () {
    return response()->json(['status' => 'ok'], 200);
});

// API Root - Información de la API
Route::get('/', function () {
    return response()->json([
        'message' => 'DocQR API - Sistema de Gestión de Documentos con QR',
        'version' => '1.0.0',
        'status' => 'active',
        'endpoints' => [
            'health' => '/up',
            'api' => '/api',
            'login' => '/api/auth/login',
            'documents' => '/api/documents',
        ],
        'note' => 'Esta es una API REST. El frontend se ejecuta en http://localhost:4200'
    ], 200);
});

// Servir archivos estáticos del frontend (solo si se necesita en producción)
// En desarrollo, el frontend se ejecuta en su propio servidor (puerto 4200)
Route::get('/frontend/{path}', function ($path) {
    $staticExtensions = ['js', 'css', 'ico', 'png', 'jpg', 'jpeg', 'gif', 'svg', 'woff', 'woff2', 'ttf', 'eot', 'json', 'map', 'mjs'];
    $extension = pathinfo($path, PATHINFO_EXTENSION);
    
    if (in_array(strtolower($extension), $staticExtensions)) {
        $filePath = public_path("frontend/{$path}");
        
        if (file_exists($filePath) && is_file($filePath)) {
            $mimeTypes = [
                'js' => 'application/javascript',
                'mjs' => 'application/javascript',
                'css' => 'text/css',
                'json' => 'application/json',
                'map' => 'application/json',
                'ico' => 'image/x-icon',
                'png' => 'image/png',
                'jpg' => 'image/jpeg',
                'jpeg' => 'image/jpeg',
                'gif' => 'image/gif',
                'svg' => 'image/svg+xml',
                'woff' => 'font/woff',
                'woff2' => 'font/woff2',
                'ttf' => 'font/ttf',
                'eot' => 'application/vnd.ms-fontobject',
            ];
            
            $mimeType = $mimeTypes[strtolower($extension)] ?? 'application/octet-stream';
            
            return response()->file($filePath, [
                'Content-Type' => $mimeType . '; charset=utf-8'
            ]);
        }
    }
    
    abort(404);
})->where('path', '.*');

// ============================================
// Rutas públicas para acceso por folder_name/código del documento (URLs legacy)
// Estas rutas permiten acceder a documentos usando el código en lugar del hash QR
// Ejemplos:
//   - /doc/IN-N-804-25-CO12
//   - /doc/IN-N°-358-25-SU24 (con símbolo de grado)
//   - /auth/login-download-file/IN-N-804-25-CO12
// ============================================
Route::get('/doc/{folderName}', [\App\Http\Controllers\ViewController::class, 'viewByFolderName'])
    ->where('folderName', '[a-zA-Z0-9\-_.°ºñÑ%Â@\s]+');

Route::get('/auth/login-download-file/{folderName}', [\App\Http\Controllers\ViewController::class, 'viewByFolderName'])
    ->where('folderName', '[a-zA-Z0-9\-_.°ºñÑ%Â@\s]+');

// Endpoint de diagnóstico para pruebas (solo desarrollo/local)
Route::get('/debug/doc/{code}', function($code) {
    $code = urldecode($code);
    $results = \App\Models\QrFile::where('folder_name', 'LIKE', "%{$code}%")
        ->select('id', 'folder_name', 'qr_id', 'final_path', 'file_path')
        ->get();
    
    return response()->json([
        'search_term' => $code,
        'total_matches' => $results->count(),
        'with_qr_id' => $results->whereNotNull('qr_id')->count(),
        'with_paths' => $results->filter(fn($r) => $r->final_path || $r->file_path)->count(),
        'results' => $results
    ]);
});

// Todas las demás rutas que no sean /api/* o /up devuelven 404 JSON
// ============================================
// Rutas para interceptar URLs antiguas de uploads que dan 404
// Ejemplo: uploads/document/IN/202508/N-1116-25-CO12/archivo.pdf
// ============================================
Route::get('uploads/document/{type}/{date}/{folderName}/{fileName}', [\App\Http\Controllers\ViewController::class, 'handleLegacyUploadUrl'])
    ->where('folderName', '[a-zA-Z0-9\-_.°ºñÑ%Â@\s]+');

Route::fallback(function () {

    return response()->json([
        'error' => 'Not Found',
        'message' => 'Esta es una API REST. Las rutas de la API están en /api/*',
        'endpoints' => [
            'health' => '/up',
            'api' => '/api',
            'login' => '/api/auth/login',
            'documents' => '/api/documents',
        ],
        'note' => 'El frontend se ejecuta en http://localhost:4200'
    ], 404);
});