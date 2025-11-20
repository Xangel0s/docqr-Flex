<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Configuración simple y segura para producción.
    | El frontend permitido se define en .env con FRONTEND_URL
    |
    */

    'paths' => ['api/*', 'view/*', 'files/*'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'OPTIONS'],

    'allowed_origins' => env('APP_ENV') === 'production' 
        ? [env('FRONTEND_URL', 'https://docqr.geofal.com.pe')]
        : ['http://localhost:4200', 'http://127.0.0.1:4200'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Content-Type', 'Accept', 'Authorization', 'X-Requested-With'],

    'exposed_headers' => ['Content-Disposition', 'Content-Type', 'Content-Length'],

    'max_age' => 86400,

    'supports_credentials' => true,

];

