<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Configuración optimizada para solucionar el error "Header not allowed".
    |
    */

    'paths' => ['api/*', 'view/*', 'files/*', 'up', 'sanctum/csrf-cookie'],

    // Permite todos los verbos HTTP (GET, POST, PUT, DELETE, etc.)
    'allowed_methods' => ['*'],

    // Lee los orígenes desde el .env. Si falla, usa los de producción por defecto.
    'allowed_origins' => explode(',', env('CORS_ALLOWED_ORIGINS', 'https://docqr.geofal.com.pe,http://localhost:4200')),

    'allowed_origins_patterns' => [],

    // ¡AQUI ESTA LA SOLUCION! 
    // Al poner '*', aceptamos 'x-frontend-origin' y cualquier otro header automáticamente.
    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    // Necesario en true para que funcione el Login (Cookies/Tokens)
    'supports_credentials' => true,

];