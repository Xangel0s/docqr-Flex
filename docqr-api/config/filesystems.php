<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Aquí puedes especificar el sistema de archivos predeterminado que debe
    | ser usado por el framework. El disco "local" así como una variedad de
    | discos en la nube están disponibles para tu aplicación.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Aquí puedes configurar tantos "discos" de sistema de archivos como desees,
    | y puedes configurar cada uno de ellos para que use un controlador y
    | ubicación diferentes.
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app'),
            'throw' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => env('APP_URL').'/storage',
            'visibility' => 'public',
            'throw' => false,
        ],

        'uploads' => [
            'driver' => 'local',
            'root' => storage_path('app/uploads'),
            'url' => env('APP_URL').'/storage/uploads',
            'visibility' => 'public',
            'throw' => false,
        ],

        'qrcodes' => [
            'driver' => 'local',
            'root' => storage_path('app/qrcodes'),
            'url' => env('APP_URL').'/storage/qrcodes',
            'visibility' => 'public',
            'throw' => false,
        ],

        'final' => [
            'driver' => 'local',
            'root' => storage_path('app/final'),
            'url' => env('APP_URL').'/storage/final',
            'visibility' => 'public',
            'throw' => false,
        ],

    ],

];

