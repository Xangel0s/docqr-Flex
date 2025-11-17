<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // CRÍTICO: TrustProxies debe estar al inicio para detectar correctamente HTTPS
        // Esto es esencial para ngrok, load balancers y proxies en producción
        // Se registra primero para que procese los headers antes que otros middlewares
        $middleware->web(prepend: [
            \App\Http\Middleware\TrustProxies::class,
        ]);
        $middleware->api(prepend: [
            \App\Http\Middleware\TrustProxies::class,
            \App\Http\Middleware\HandleCorsOptions::class, // Manejar OPTIONS antes que HandleCors
            \Illuminate\Http\Middleware\HandleCors::class,
        ]);
        
        // Deshabilitar FrameGuard completamente para permitir iframes
        // En producción, se maneja manualmente en FileController
        $middleware->web(remove: [
            \Illuminate\Http\Middleware\FrameGuard::class,
        ]);
        $middleware->api(remove: [
            \Illuminate\Http\Middleware\FrameGuard::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();

