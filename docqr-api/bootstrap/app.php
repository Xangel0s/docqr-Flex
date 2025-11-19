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
            \App\Http\Middleware\SecurityHeaders::class, // Headers de seguridad
        ]);
        $middleware->api(prepend: [
            \App\Http\Middleware\TrustProxies::class,
            \App\Http\Middleware\HandleCorsOptions::class, // Manejar OPTIONS antes que HandleCors
            \Illuminate\Http\Middleware\HandleCors::class,
            \App\Http\Middleware\SecurityHeaders::class, // Headers de seguridad
        ]);
        
        // Deshabilitar FrameGuard completamente para permitir iframes
        // En producción, se maneja manualmente en SecurityHeaders middleware
        $middleware->web(remove: [
            \Illuminate\Http\Middleware\FrameGuard::class,
        ]);
        $middleware->api(remove: [
            \Illuminate\Http\Middleware\FrameGuard::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // CRÍTICO: Para rutas API, siempre devolver JSON en lugar de HTML
        // Esto previene que los errores 404/500 devuelvan páginas HTML cuando se espera PDF/JSON
        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException $e, \Illuminate\Http\Request $request) {
            if ($request->is('api/*') || str_starts_with($request->path(), 'api/')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Recurso no encontrado',
                    'path' => $request->path()
                ], 404)->header('Content-Type', 'application/json');
            }
        });
        
        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\HttpException $e, \Illuminate\Http\Request $request) {
            if ($request->is('api/*') || str_starts_with($request->path(), 'api/')) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage() ?: 'Error en el servidor',
                    'status' => $e->getStatusCode(),
                    'path' => $request->path()
                ], $e->getStatusCode())->header('Content-Type', 'application/json');
            }
        });
    })->create();

