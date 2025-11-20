<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Configuración completa para desarrollo y producción.
    | Los orígenes permitidos se configuran desde .env
    |
    */

    'paths' => ['api/*', 'view/*', 'files/*'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'OPTIONS', 'HEAD'],

    'allowed_origins' => env('APP_ENV') === 'production' 
        ? array_filter(explode(',', env('CORS_ALLOWED_ORIGINS', env('FRONTEND_URL', 'https://docqr.geofal.com.pe'))))
        : ['http://localhost:4200', 'http://127.0.0.1:4200'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => [
        'Content-Type',
        'Accept',
        'Authorization',
        'X-Requested-With',
        'X-Frontend-Origin',
        'Origin',
        'Cache-Control',
        'Pragma',
        'If-None-Match',
        'If-Modified-Since'
    ],

    'exposed_headers' => [
        'Content-Type',
        'Content-Length',
        'Content-Disposition',
        'ETag',
        'Cache-Control',
        'X-Content-Type-Options'
    ],

    'max_age' => 86400,

    'supports_credentials' => true,

];

