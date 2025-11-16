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

    'paths' => ['api/*', 'view/*', 'api/files/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        'https://docqr.geofal.com.pe',
        'http://localhost:4200',
        'http://127.0.0.1:4200',
    ],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];

