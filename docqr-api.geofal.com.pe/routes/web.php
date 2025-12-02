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

// Todas las demás rutas que no sean /api/* o /up devuelven 404 JSON
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
