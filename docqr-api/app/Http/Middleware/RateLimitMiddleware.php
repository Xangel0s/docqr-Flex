<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware para rate limiting
 * Previene abuso y mejora el rendimiento con múltiples usuarios
 */
class RateLimitMiddleware
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next, string $key = 'default', int $maxAttempts = 60, int $decayMinutes = 1): Response
    {
        $limiterKey = $key . ':' . $request->ip() . ':' . ($request->user()?->id ?? 'guest');
        
        if (RateLimiter::tooManyAttempts($limiterKey, $maxAttempts)) {
            $seconds = RateLimiter::availableIn($limiterKey);
            
            return response()->json([
                'success' => false,
                'message' => 'Demasiadas solicitudes. Por favor, intenta de nuevo en ' . $seconds . ' segundos.',
                'retry_after' => $seconds
            ], 429)->header('Retry-After', $seconds);
        }
        
        RateLimiter::hit($limiterKey, $decayMinutes * 60);
        
        $response = $next($request);
        
        // Agregar headers de rate limit
        $response->headers->set('X-RateLimit-Limit', $maxAttempts);
        $response->headers->set('X-RateLimit-Remaining', max(0, $maxAttempts - RateLimiter::attempts($limiterKey)));
        
        return $response;
    }
}

