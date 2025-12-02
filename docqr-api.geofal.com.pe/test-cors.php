<?php
/**
 * Script de diagnóstico CORS
 * Subir a: /home/grersced/docqr-api.geofal.com.pe/public/test-cors.php
 * Acceder: https://docqr-api.geofal.com.pe/test-cors.php
 */

// Permitir cualquier origen para diagnóstico
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, PATCH, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept, Authorization, X-Requested-With');
header('Access-Control-Allow-Credentials: true');
header('Content-Type: application/json');

// Si es una petición OPTIONS, responder inmediatamente
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Leer .env si existe
$envPath = dirname(__DIR__) . '/.env';
$envVars = [];
if (file_exists($envPath)) {
    $envContent = file_get_contents($envPath);
    $lines = explode("\n", $envContent);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line && !str_starts_with($line, '#')) {
            $parts = explode('=', $line, 2);
            if (count($parts) === 2) {
                $key = trim($parts[0]);
                $value = trim($parts[1]);
                $envVars[$key] = $value;
            }
        }
    }
}

// Información de diagnóstico
$diagnostico = [
    'status' => 'OK',
    'timestamp' => date('Y-m-d H:i:s'),
    'server' => [
        'php_version' => PHP_VERSION,
        'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown',
        'request_uri' => $_SERVER['REQUEST_URI'] ?? 'unknown',
        'http_origin' => $_SERVER['HTTP_ORIGIN'] ?? 'no origin header',
        'server_name' => $_SERVER['SERVER_NAME'] ?? 'unknown',
        'https' => isset($_SERVER['HTTPS']) ? $_SERVER['HTTPS'] : 'not set',
    ],
    'env_critical' => [
        'APP_ENV' => $envVars['APP_ENV'] ?? 'NOT SET',
        'APP_DEBUG' => $envVars['APP_DEBUG'] ?? 'NOT SET',
        'APP_URL' => $envVars['APP_URL'] ?? 'NOT SET',
        'FRONTEND_URL' => $envVars['FRONTEND_URL'] ?? 'NOT SET',
        'CORS_ALLOWED_ORIGINS' => $envVars['CORS_ALLOWED_ORIGINS'] ?? 'NOT SET',
        'DB_DATABASE' => $envVars['DB_DATABASE'] ?? 'NOT SET',
    ],
    'cors_headers_sent' => [
        'Access-Control-Allow-Origin' => '*',
        'Access-Control-Allow-Methods' => 'GET, POST, PUT, DELETE, PATCH, OPTIONS',
        'Access-Control-Allow-Headers' => 'Content-Type, Accept, Authorization',
    ],
    'files_check' => [
        '.env_exists' => file_exists($envPath),
        'vendor_exists' => is_dir(dirname(__DIR__) . '/vendor'),
        'storage_writable' => is_writable(dirname(__DIR__) . '/storage'),
        'bootstrap_cache_writable' => is_writable(dirname(__DIR__) . '/bootstrap/cache'),
    ],
    'instructions' => [
        'Si ves este mensaje, el servidor PHP funciona',
        'Si CORS_ALLOWED_ORIGINS dice NOT SET, edita .env',
        'Si APP_ENV no es production, cambialo',
        'Accede desde el frontend para ver si CORS funciona',
    ]
];

echo json_encode($diagnostico, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

