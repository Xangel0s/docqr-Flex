<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'message' => 'DocQR API - Sistema de Gestión de Documentos con QR',
        'version' => '1.0.0',
        'status' => 'active'
    ]);
});

