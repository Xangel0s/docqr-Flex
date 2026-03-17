<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware para comprimir respuestas
 * Reduce el tamaño de las respuestas JSON para mejorar el rendimiento
 */
class CompressResponse
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        
        // Solo comprimir respuestas JSON y texto
        $contentType = $response->headers->get('Content-Type', '');
        
        if (str_contains($contentType, 'application/json') || str_contains($contentType, 'text/')) {
            $content = $response->getContent();
            
            // Comprimir si el contenido es mayor a 1KB
            if (strlen($content) > 1024) {
                $compressed = gzencode($content, 6); // Nivel 6: buen balance entre compresión y velocidad
                
                if ($compressed !== false && strlen($compressed) < strlen($content)) {
                    $response->setContent($compressed);
                    $response->headers->set('Content-Encoding', 'gzip');
                    $response->headers->set('Content-Length', strlen($compressed));
                }
            }
        }
        
        return $response;
    }
}

