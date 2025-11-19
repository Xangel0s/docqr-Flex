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

        // Solo aplicar en producción (o cuando APP_ENV no sea 'local')
        if (config('app.env') === 'production') {
            
            // X-Frame-Options: Prevenir clickjacking
            // DENY: No permitir que el sitio se muestre en iframes
            // SAMEORIGIN: Permitir iframes solo del mismo dominio
            $response->headers->set('X-Frame-Options', 'SAMEORIGIN');

            // X-Content-Type-Options: Prevenir MIME sniffing
            // nosniff: El navegador no intentará adivinar el tipo de contenido
            $response->headers->set('X-Content-Type-Options', 'nosniff');

            // X-XSS-Protection: Protección contra XSS (navegadores antiguos)
            // 1; mode=block: Activar protección XSS y bloquear página si se detecta
            $response->headers->set('X-XSS-Protection', '1; mode=block');

            // Strict-Transport-Security (HSTS): Forzar HTTPS
            // max-age=31536000: Recordar por 1 año
            // includeSubDomains: Aplicar a todos los subdominios
            // preload: Permitir inclusión en listas de precarga de navegadores
            if ($request->secure()) {
                $response->headers->set(
                    'Strict-Transport-Security',
                    'max-age=31536000; includeSubDomains; preload'
                );
            }

            // Referrer-Policy: Controlar información de referrer
            // no-referrer-when-downgrade: No enviar referrer si es de HTTPS a HTTP
            $response->headers->set('Referrer-Policy', 'no-referrer-when-downgrade');

            // Permissions-Policy: Controlar características del navegador
            // Deshabilitar características no necesarias para mejorar seguridad
            $response->headers->set(
                'Permissions-Policy',
                'geolocation=(), microphone=(), camera=(), payment=()'
            );

            // Content-Security-Policy: Política de seguridad de contenido
            // Define qué recursos pueden cargarse y desde dónde
            // NOTA: frame-ancestors debe estar correctamente formateado (sin espacios extra)
            $appUrl = config('app.url');
            $csp = [
                "default-src 'self'",
                "script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdn.jsdelivr.net",
                "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
                "font-src 'self' https://fonts.gstatic.com data:",
                "img-src 'self' data: https:",
                "connect-src 'self' " . $appUrl,
                "frame-ancestors 'self'",
                "base-uri 'self'",
                "form-action 'self'",
            ];
            
            // Construir CSP con separador correcto (sin espacios extra)
            $cspString = implode('; ', $csp);
            $response->headers->set('Content-Security-Policy', $cspString);

            // Ocultar información del servidor (si es posible)
            $response->headers->remove('X-Powered-By');
            $response->headers->remove('Server');
        }

        // CORS se maneja en config/cors.php y HandleCors middleware
        // No duplicar lógica CORS aquí para evitar conflictos

        return $response;
    }
}
