<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware para agregar headers de seguridad en producción
 * 
 * Este middleware implementa las mejores prácticas de seguridad HTTP:
 * - Prevención de XSS
 * - Protección contra clickjacking
 * - Content Security Policy
 * - HSTS para forzar HTTPS
 * - Protección de información del servidor
 */
class SecurityHeaders
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (config('app.env') === 'production') {
            // X-Frame-Options se maneja con CSP frame-ancestors (más flexible)
            // Permitir iframes desde el frontend
            $frontendUrl = config('app.frontend_url', env('FRONTEND_URL', 'https://docqr.geofal.com.pe'));
            $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
            $response->headers->set('X-Content-Type-Options', 'nosniff');
            $response->headers->set('X-XSS-Protection', '1; mode=block');

            if ($request->secure()) {
                $response->headers->set(
                    'Strict-Transport-Security',
                    'max-age=31536000; includeSubDomains; preload'
                );
            }

            $response->headers->set('Referrer-Policy', 'no-referrer-when-downgrade');

            // Permissions-Policy: Permitir fullscreen para el visor de PDF
            $response->headers->set(
                'Permissions-Policy',
                'geolocation=(), microphone=(), camera=(), payment=(), fullscreen=(self "' . $frontendUrl . '")'
            );
            $appUrl = config('app.url');
            $frontendUrl = config('app.frontend_url', env('FRONTEND_URL', 'https://docqr.geofal.com.pe'));
            
            $csp = [
                "default-src 'self'",
                "script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdn.jsdelivr.net",
                "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
                "font-src 'self' https://fonts.gstatic.com data:",
                "img-src 'self' data: https: blob:",
                "connect-src 'self' " . $appUrl . " " . $frontendUrl,
                "frame-ancestors 'self' " . $frontendUrl . " https://docqr.geofal.com.pe https://www.docqr.geofal.com.pe",
                "frame-src 'self' " . $frontendUrl . " https://docqr.geofal.com.pe blob:",
                "base-uri 'self'",
                "form-action 'self'",
            ];
            
            $cspString = implode('; ', $csp);
            $response->headers->set('Content-Security-Policy', $cspString);

            $response->headers->remove('X-Powered-By');
            $response->headers->remove('Server');
        }

        return $response;
    }
}
