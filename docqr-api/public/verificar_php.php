<?php
/**
 * Script de verificación de PHP para DocQR
 * Acceder: https://docqr-api.geofal.com.pe/verificar_php.php
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$info = [
    'php_version' => PHP_VERSION,
    'memory_limit' => ini_get('memory_limit'),
    'max_execution_time' => ini_get('max_execution_time'),
    'max_input_time' => ini_get('max_input_time'),
    'upload_max_filesize' => ini_get('upload_max_filesize'),
    'post_max_size' => ini_get('post_max_size'),
    'max_input_vars' => ini_get('max_input_vars'),
    'default_socket_timeout' => ini_get('default_socket_timeout'),
    'memory_usage' => [
        'current' => memory_get_usage(true),
        'current_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
        'peak' => memory_get_peak_usage(true),
        'peak_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
    ],
    'extensions' => [
        'gd' => extension_loaded('gd'),
        'imagick' => extension_loaded('imagick'),
        'zip' => extension_loaded('zip'),
        'mbstring' => extension_loaded('mbstring'),
        'openssl' => extension_loaded('openssl'),
    ],
    'storage' => [
        'storage_writable' => is_writable(dirname(__DIR__) . '/storage'),
        'storage_path' => dirname(__DIR__) . '/storage',
        'disk_free_space' => disk_free_space(dirname(__DIR__)),
        'disk_free_space_gb' => round(disk_free_space(dirname(__DIR__)) / 1024 / 1024 / 1024, 2),
    ],
    'recommendations' => [],
];

// Verificar límites recomendados
$memoryLimitBytes = return_bytes($info['memory_limit']);
if ($memoryLimitBytes < 1024 * 1024 * 1024) {
    $info['recommendations'][] = 'memory_limit debería ser al menos 1024M (actual: ' . $info['memory_limit'] . ')';
}

if ($info['max_execution_time'] < 600) {
    $info['recommendations'][] = 'max_execution_time debería ser al menos 600 (actual: ' . $info['max_execution_time'] . ')';
}

$uploadMaxBytes = return_bytes($info['upload_max_filesize']);
if ($uploadMaxBytes < 500 * 1024 * 1024) {
    $info['recommendations'][] = 'upload_max_filesize debería ser al menos 500M (actual: ' . $info['upload_max_filesize'] . ')';
}

function return_bytes($val) {
    $val = trim($val);
    $last = strtolower($val[strlen($val)-1]);
    $val = (int)$val;
    switch($last) {
        case 'g': $val *= 1024;
        case 'm': $val *= 1024;
        case 'k': $val *= 1024;
    }
    return $val;
}

echo json_encode($info, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

