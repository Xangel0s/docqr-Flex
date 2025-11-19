<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Aquí puedes configurar los ajustes para compartir recursos de origen cruzado
    | o "CORS". Esto determina qué operaciones de origen cruzado pueden ejecutarse
    | en los navegadores web. Puedes ajustar estos ajustes según sea necesario.
    |
    */

    'paths' => ['api/*', 'view/*', 'api/files/*', 'files/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_merge([
        'https://docqr.geofal.com.pe', // Dominio de producción (SIEMPRE permitido)
    ], in_array(env('APP_ENV', 'production'), ['local', 'development']) ? [
        // Solo en desarrollo/local: permitir localhost y ngrok
        'http://localhost:4200',
        'http://127.0.0.1:4200',
        'http://localhost',
        'http://127.0.0.1',
    ] : []),

    /*
    |--------------------------------------------------------------------------
    | URLs de ngrok para pruebas de producción
    |--------------------------------------------------------------------------
    |
    | Las URLs de ngrok cambian cada vez que se reinicia (versión gratuita).
    | Agrega aquí la URL de ngrok cuando la obtengas, o usa allowed_origins_patterns
    | para permitir todas las URLs de ngrok automáticamente.
    |
    */

    'allowed_origins_patterns' => array_merge([
        // Permitir todas las URLs de ngrok (ej: https://abc123.ngrok.io)
        '#^https://[a-z0-9-]+\.ngrok\.io$#',
        '#^https://[a-z0-9-]+\.ngrok-free\.app$#',
        '#^https://[a-z0-9-]+\.ngrok-free\.dev$#',
    ], in_array(env('APP_ENV', 'production'), ['local', 'development']) ? [
        // En desarrollo, permitir cualquier origen (útil para ngrok y localhost)
        '#.*#'
    ] : []),

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];

