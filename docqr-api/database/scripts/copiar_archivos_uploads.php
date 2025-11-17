<?php

/**
 * Script para copiar archivos PDF desde uploads/document/ a storage/app/uploads/
 * 
 * Este script copia los archivos físicos para que el sistema Laravel los pueda leer
 * 
 * USO:
 * php database/scripts/copiar_archivos_uploads.php
 */

$rootDir = realpath(__DIR__ . '/../..');

if (!$rootDir) {
    $rootDir = dirname(__DIR__, 2);
}

$autoloadPath = $rootDir . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
if (!file_exists($autoloadPath)) {
    die("❌ ERROR: No se encontró vendor/autoload.php. Ejecuta 'composer install' primero.\n");
}

require $autoloadPath;

$bootstrapPath = $rootDir . DIRECTORY_SEPARATOR . 'bootstrap' . DIRECTORY_SEPARATOR . 'app.php';
if (!file_exists($bootstrapPath)) {
    die("❌ ERROR: No se encontró bootstrap/app.php.\n");
}

$app = require_once $bootstrapPath;
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

echo "\n=== COPIAR ARCHIVOS PDF A STORAGE ===\n\n";

// Ruta origen: uploads/document/ (desde la raíz del proyecto)
$uploadsOrigen = realpath($rootDir . '/../uploads/document');

if (!$uploadsOrigen || !is_dir($uploadsOrigen)) {
    echo "❌ ERROR: No se encontró la carpeta 'uploads/document/' en: " . dirname($rootDir) . "/uploads/document\n";
    echo "   Asegúrate de que los archivos estén en esa ubicación.\n\n";
    exit(1);
}

echo "📁 Carpeta origen: {$uploadsOrigen}\n";

// Ruta destino: storage/app/uploads/ (Laravel)
$uploadsDestino = Storage::disk('local')->path('uploads');

echo "📁 Carpeta destino: {$uploadsDestino}\n\n";

// Función recursiva para copiar archivos
function copiarArchivosRecursivo($origen, $destino, $baseOrigen, &$stats) {
    $items = scandir($origen);
    
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        
        $rutaOrigen = $origen . DIRECTORY_SEPARATOR . $item;
        $rutaRelativa = str_replace($baseOrigen . DIRECTORY_SEPARATOR, '', $rutaOrigen);
        $rutaDestino = $destino . DIRECTORY_SEPARATOR . $rutaRelativa;
        
        if (is_dir($rutaOrigen)) {
            // Crear directorio en destino si no existe
            if (!is_dir($rutaDestino)) {
                mkdir($rutaDestino, 0755, true);
            }
            // Recursión
            copiarArchivosRecursivo($rutaOrigen, $destino, $baseOrigen, $stats);
        } elseif (is_file($rutaOrigen) && strtolower(pathinfo($item, PATHINFO_EXTENSION)) === 'pdf') {
            // Copiar archivo PDF
            $dirDestino = dirname($rutaDestino);
            if (!is_dir($dirDestino)) {
                mkdir($dirDestino, 0755, true);
            }
            
            // Solo copiar si no existe o si es más reciente
            if (!file_exists($rutaDestino) || filemtime($rutaOrigen) > filemtime($rutaDestino)) {
                if (copy($rutaOrigen, $rutaDestino)) {
                    $stats['copiados']++;
                    echo "   ✅ Copiado: {$rutaRelativa}\n";
                } else {
                    $stats['errores']++;
                    echo "   ❌ Error copiando: {$rutaRelativa}\n";
                }
            } else {
                $stats['omitidos']++;
            }
        }
    }
}

$stats = [
    'copiados' => 0,
    'omitidos' => 0,
    'errores' => 0
];

echo "🔄 Copiando archivos PDF...\n\n";

// Asegurar que el directorio destino existe
if (!is_dir($uploadsDestino)) {
    mkdir($uploadsDestino, 0755, true);
}

// Copiar archivos
copiarArchivosRecursivo($uploadsOrigen, $uploadsDestino, $uploadsOrigen, $stats);

echo "\n=== RESUMEN ===\n";
echo "✅ Archivos copiados: {$stats['copiados']}\n";
echo "⏭️  Archivos omitidos (ya existían): {$stats['omitidos']}\n";
echo "❌ Errores: {$stats['errores']}\n\n";

if ($stats['errores'] > 0) {
    echo "⚠️  Hubo errores al copiar algunos archivos. Revisa los permisos.\n\n";
} else {
    echo "✅ Todos los archivos copiados exitosamente.\n";
    echo "💡 Los archivos ahora están en: storage/app/uploads/\n";
    echo "   El script de migración podrá encontrarlos.\n\n";
}

