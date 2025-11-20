<?php
/**
 * Script para verificar configuración de PHP
 * 
 * Ejecutar: php verificar_php_config.php
 */

echo "=== Configuración Actual de PHP ===\n\n";

$configs = [
    'upload_max_filesize' => ini_get('upload_max_filesize'),
    'post_max_size' => ini_get('post_max_size'),
    'memory_limit' => ini_get('memory_limit'),
    'max_execution_time' => ini_get('max_execution_time'),
    'max_input_time' => ini_get('max_input_time'),
    'max_file_uploads' => ini_get('max_file_uploads'),
];

$requerido = [
    'upload_max_filesize' => '500M',
    'post_max_size' => '510M',
    'memory_limit' => '1024M',
    'max_execution_time' => '600',
    'max_input_time' => '600',
    'max_file_uploads' => '20',
];

foreach ($configs as $key => $value) {
    $status = '❌';
    if ($key === 'upload_max_filesize' || $key === 'post_max_size' || $key === 'memory_limit') {
        // Convertir a bytes para comparar
        $current = str_replace(['M', 'G', 'K'], '', $value);
        $required = str_replace(['M', 'G', 'K'], '', $requerido[$key]);
        
        if ($current >= $required) {
            $status = '✅';
        }
    } elseif ($value >= $requerido[$key]) {
        $status = '✅';
    }
    
    echo sprintf("%s %-25s: %-10s (requerido: %s)\n", 
        $status, 
        $key, 
        $value, 
        $requerido[$key]
    );
}

echo "\n=== Archivo php.ini ===\n";
echo php_ini_loaded_file() . "\n\n";

// Calcular tamaño máximo de archivo que se puede subir
$upload_max = ini_get('upload_max_filesize');
$post_max = ini_get('post_max_size');

echo "Tamaño máximo de archivo que se puede subir: " . min($upload_max, $post_max) . "\n";

// Verificar si está todo OK
$allOk = true;
foreach ($configs as $key => $value) {
    if ($key === 'upload_max_filesize' || $key === 'post_max_size' || $key === 'memory_limit') {
        $current = str_replace(['M', 'G', 'K'], '', $value);
        $required = str_replace(['M', 'G', 'K'], '', $requerido[$key]);
        if ($current < $required) {
            $allOk = false;
            break;
        }
    } elseif ($value < $requerido[$key]) {
        $allOk = false;
        break;
    }
}

echo "\n=== Estado ===\n";
if ($allOk) {
    echo "✅ La configuración de PHP es correcta. Puedes subir archivos de hasta 500MB.\n";
} else {
    echo "❌ La configuración de PHP necesita ajustes.\n";
    echo "   Edita el archivo php.ini y reinicia el servidor.\n";
    echo "   Ver instrucciones en: CONFIGURAR_PHP.md\n";
}

