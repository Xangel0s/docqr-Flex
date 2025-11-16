<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\UploadController;
use App\Http\Controllers\EmbedController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\ViewController;
use App\Http\Controllers\SystemController;
use App\Http\Controllers\CompressionController;
use App\Http\Controllers\FileController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Aquí se definen las rutas de la API REST para el sistema DocQR.
| Todas las rutas están prefijadas con /api
|
*/

// Ruta para subir PDF y generar QR
Route::post('/upload', [UploadController::class, 'upload']);

// Ruta para embebir QR en PDF con posición
Route::put('/embed', [EmbedController::class, 'embed']);

// Ruta para recibir PDF modificado con pdf-lib (método iLovePDF)
Route::put('/embed-pdf', [EmbedController::class, 'embedPdf']);

// Rutas para gestión de documentos
Route::get('/documents', [DocumentController::class, 'index']);
Route::get('/documents/stats', [DocumentController::class, 'stats']);
Route::get('/documents/qr/{qrId}', [DocumentController::class, 'showByQrId']);
Route::get('/documents/{id}', [DocumentController::class, 'show']);
Route::delete('/documents/{id}', [DocumentController::class, 'destroy']);

// Ruta pública para visualizar PDF con QR (incrementa contador de escaneos)
Route::get('/view/{hash}', [ViewController::class, 'view']);

// Rutas para servir archivos (PDFs y QRs)
Route::get('/files/pdf/{qrId}', [FileController::class, 'servePdf']);
Route::get('/files/qr/{qrId}', [FileController::class, 'serveQr']);

// Rutas del sistema
Route::get('/system/compression-status', [SystemController::class, 'compressionStatus']);

// Rutas de compresión manual
Route::get('/compression/list', [CompressionController::class, 'listCompressible']);
Route::post('/compression/compress', [CompressionController::class, 'compressByMonth']);
Route::get('/compression/download', [CompressionController::class, 'downloadZip']);

