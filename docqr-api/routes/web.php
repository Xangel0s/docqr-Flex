<?php

use Illuminate\Support\Facades\Route;

// Servir Frontend Angular (SPA) en la raíz
Route::get('/', function () {
    $frontendPath = public_path('frontend/index.html');
    
    // Si existe el frontend build, servirlo
    if (file_exists($frontendPath)) {
        return response()->file($frontendPath, [
            'Content-Type' => 'text/html; charset=utf-8'
        ]);
    }
    
    // Si no existe, mostrar mensaje informativo
    return response()->json([
        'message' => 'Frontend no encontrado. Ejecuta: npm run build:frontend',
        'instructions' => [
            '1. cd docqr-api',
            '2. npm run build:frontend',
            '3. O manualmente: cd docqr-frontend && ng build --configuration=production'
        ]
    ], 404);
});

// API Root (mover a /api/status o similar si se necesita)
// Route::get('/api/status', function () {
//     return response()->json([
//         'message' => 'DocQR API - Sistema de Gestión de Documentos con QR',
//         'version' => '1.0.0',
//         'status' => 'active'
//     ]);
// });

// Servir Frontend Angular (SPA) para todas las demás rutas que no sean /api/*
// IMPORTANTE: Esta ruta también maneja archivos estáticos antes de servir el index.html
Route::get('/{any}', function ($any) {
    // Primero, verificar si es un archivo estático (JS, CSS, imágenes, etc.)
    $staticExtensions = ['js', 'css', 'ico', 'png', 'jpg', 'jpeg', 'gif', 'svg', 'woff', 'woff2', 'ttf', 'eot', 'json', 'map', 'mjs'];
    $extension = pathinfo($any, PATHINFO_EXTENSION);
    
    if (in_array(strtolower($extension), $staticExtensions)) {
        // Construir la ruta del archivo (puede estar en subdirectorios como assets/)
        $filePath = public_path("frontend/{$any}");
        
        if (file_exists($filePath) && is_file($filePath)) {
            // Determinar el MIME type correcto
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
        
        // Si el archivo estático no existe, devolver 404
        abort(404);
    }
    
    // Si no es un archivo estático, servir el index.html para SPA routing
    $frontendPath = public_path('frontend/index.html');
    
    if (file_exists($frontendPath)) {
        return response()->file($frontendPath, [
            'Content-Type' => 'text/html; charset=utf-8'
        ]);
    }
    
    // Si no existe el frontend, 404
    abort(404);
})->where('any', '^(?!api).*$'); // Excluir rutas que empiecen con 'api'
