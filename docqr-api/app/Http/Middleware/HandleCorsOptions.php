<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware para manejar peticiones CORS (OPTIONS y todas las demás)
 * 
 * Este middleware responde a las peticiones OPTIONS (preflight) y también
 * agrega los headers CORS a todas las respuestas para permitir peticiones
 * desde el frontend en un dominio diferente.
 */
class HandleCorsOptions
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Obtener el origen de la petición
        $origin = $request->header('Origin');
        
        // Lista de orígenes permitidos (desde config o .env)
        $allowedOrigins = $this->getAllowedOrigins();
        
        // Verificar si el origen está permitido
        $isOriginAllowed = $this->isOriginAllowed($origin, $allowedOrigins);
        
        // Si es una petición OPTIONS (preflight), responder inmediatamente con headers CORS
        if ($request->isMethod('OPTIONS')) {
            $allowedOrigin = $isOriginAllowed ? $origin : ($allowedOrigins[0] ?? '*');
            
            return response('', 204)
                ->header('Access-Control-Allow-Origin', $allowedOrigin)
                ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, PATCH, OPTIONS, HEAD')
                ->header('Access-Control-Allow-Headers', 'Content-Type, Accept, Authorization, X-Requested-With, X-Frontend-Origin')
                ->header('Access-Control-Expose-Headers', 'Content-Type, Content-Length, Content-Disposition, ETag')
                ->header('Access-Control-Max-Age', '86400')
                ->header('Access-Control-Allow-Credentials', 'false');
        }

        // Para todas las demás peticiones, procesar y agregar headers CORS a la respuesta
        $response = $next($request);
        
        // Agregar headers CORS a la respuesta si el origen está permitido
        if ($isOriginAllowed && $origin) {
            $response->headers->set('Access-Control-Allow-Origin', $origin);
            $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, PATCH, OPTIONS, HEAD');
            $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Accept, Authorization, X-Requested-With, X-Frontend-Origin');
            $response->headers->set('Access-Control-Expose-Headers', 'Content-Type, Content-Length, Content-Disposition, ETag');
            $response->headers->set('Access-Control-Allow-Credentials', 'false');
        } elseif (in_array('*', $allowedOrigins)) {
            // Si se permite cualquier origen, usar '*'
            $response->headers->set('Access-Control-Allow-Origin', '*');
            $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, PATCH, OPTIONS, HEAD');
            $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Accept, Authorization, X-Requested-With, X-Frontend-Origin');
            $response->headers->set('Access-Control-Expose-Headers', 'Content-Type, Content-Length, Content-Disposition, ETag');
            $response->headers->set('Access-Control-Allow-Credentials', 'false');
        }

        return $response;
    }
    
    /**
     * Obtener orígenes permitidos desde la configuración
     */
    private function getAllowedOrigins(): array
    {
        // Leer desde config/cors.php
        $configOrigins = config('cors.allowed_origins', []);
        
        // Si está en producción, usar solo los orígenes específicos
        // Si está en desarrollo, permitir cualquier origen
        if (app()->environment('production')) {
            $filtered = array_filter($configOrigins, function($origin) {
                return $origin !== '*';
            });
            // Si no hay orígenes específicos, usar los de la configuración tal cual
            return !empty($filtered) ? array_values($filtered) : $configOrigins;
        }
        
        // En desarrollo, permitir cualquier origen
        return ['*'];
    }
    
    /**
     * Verificar si un origen está permitido
     */
    private function isOriginAllowed(?string $origin, array $allowedOrigins): bool
    {
        if (!$origin) {
            return false;
        }
        
        // Si se permite cualquier origen
        if (in_array('*', $allowedOrigins)) {
            return true;
        }
        
        // Verificar coincidencia exacta
        if (in_array($origin, $allowedOrigins)) {
            return true;
        }
        
        // Verificar patrones (para ngrok, etc.)
        $patterns = config('cors.allowed_origins_patterns', []);
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $origin)) {
                return true;
            }
        }
        
        return false;
    }
}

