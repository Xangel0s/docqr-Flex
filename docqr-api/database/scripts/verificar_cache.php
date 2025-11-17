<?php

/**
 * Script para verificar que la tabla cache existe
 */

$rootDir = realpath(__DIR__ . '/../..');
$autoloadPath = $rootDir . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
require $autoloadPath;

$bootstrapPath = $rootDir . DIRECTORY_SEPARATOR . 'bootstrap' . DIRECTORY_SEPARATOR . 'app.php';
$app = require_once $bootstrapPath;
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Cache;

echo "\n=== VERIFICACIÓN DE CACHE ===\n\n";

// Verificar tabla
if (Schema::hasTable('cache')) {
    echo "✅ Tabla 'cache' existe\n";
    
    // Probar escribir y leer
    try {
        Cache::put('test_verificacion', 'ok', 60);
        $valor = Cache::get('test_verificacion');
        
        if ($valor === 'ok') {
            echo "✅ Cache funciona correctamente\n";
            Cache::forget('test_verificacion');
        } else {
            echo "⚠️  Cache no devolvió el valor esperado\n";
        }
    } catch (\Exception $e) {
        echo "❌ Error al usar cache: " . $e->getMessage() . "\n";
    }
} else {
    echo "❌ Tabla 'cache' NO existe\n";
    echo "   Ejecuta: php artisan migrate\n";
}

echo "\n";

