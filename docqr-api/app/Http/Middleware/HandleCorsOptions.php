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
        $origin = $request->header('Origin');
        $allowedOrigins = $this->getAllowedOrigins();
        $isOriginAllowed = $this->isOriginAllowed($origin, $allowedOrigins);
        
        if ($request->isMethod('OPTIONS')) {
            // En desarrollo, siempre permitir localhost:4200
            $env = env('APP_ENV', 'local');
            if ($env !== 'production') {
                $allowedOrigin = $isOriginAllowed && $origin ? $origin : 'http://localhost:4200';
            } else {
                $allowedOrigin = $isOriginAllowed && $origin ? $origin : ($allowedOrigins[0] ?? '*');
            }
            
            return response('', 204)
                ->header('Access-Control-Allow-Origin', $allowedOrigin)
                ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, PATCH, OPTIONS, HEAD')
                ->header('Access-Control-Allow-Headers', 'Content-Type, Accept, Authorization, X-Requested-With, X-Frontend-Origin, X-CSRF-TOKEN, Origin, Cache-Control')
                ->header('Access-Control-Expose-Headers', 'Content-Type, Content-Length, Content-Disposition, ETag')
                ->header('Access-Control-Max-Age', '86400')
                ->header('Access-Control-Allow-Credentials', 'true');
        }

        $response = $next($request);
        
        // En desarrollo, siempre agregar headers CORS para localhost:4200
        $env = env('APP_ENV', 'local');
        if ($env !== 'production') {
            // En desarrollo, permitir localhost:4200 siempre
            $corsOrigin = $isOriginAllowed && $origin ? $origin : 'http://localhost:4200';
            $response->headers->set('Access-Control-Allow-Origin', $corsOrigin);
            $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, PATCH, OPTIONS, HEAD');
            $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Accept, Authorization, X-Requested-With, X-Frontend-Origin, X-CSRF-TOKEN, Origin, Cache-Control');
            $response->headers->set('Access-Control-Expose-Headers', 'Content-Type, Content-Length, Content-Disposition, ETag');
            $response->headers->set('Access-Control-Allow-Credentials', 'true');
        } elseif ($isOriginAllowed && $origin) {
            // En producción, solo si el origen está permitido
            $response->headers->set('Access-Control-Allow-Origin', $origin);
            $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, PATCH, OPTIONS, HEAD');
            $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Accept, Authorization, X-Requested-With, X-Frontend-Origin, X-CSRF-TOKEN, Origin, Cache-Control');
            $response->headers->set('Access-Control-Expose-Headers', 'Content-Type, Content-Length, Content-Disposition, ETag');
            $response->headers->set('Access-Control-Allow-Credentials', 'true');
        } elseif (in_array('*', $allowedOrigins)) {
            $response->headers->set('Access-Control-Allow-Origin', '*');
            $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, PATCH, OPTIONS, HEAD');
            $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Accept, Authorization, X-Requested-With, X-Frontend-Origin, X-CSRF-TOKEN, Origin, Cache-Control');
            $response->headers->set('Access-Control-Expose-Headers', 'Content-Type, Content-Length, Content-Disposition, ETag');
        }

        return $response;
    }
    
    /**
     * Obtener orígenes permitidos desde la configuración
     */
    private function getAllowedOrigins(): array
    {
        $env = env('APP_ENV', 'local');
        
        if ($env === 'production') {
            $frontendUrl = env('FRONTEND_URL', 'https://docqr.geofal.com.pe');
            $corsOrigins = env('CORS_ALLOWED_ORIGINS', $frontendUrl);
            if ($corsOrigins) {
                $origins = array_filter(explode(',', $corsOrigins));
                if (!empty($origins)) {
                    return $origins;
                }
            }
            return [$frontendUrl];
        }
        
        return ['http://localhost:4200', 'http://127.0.0.1:4200'];
    }
    
    /**
     * Verificar si un origen está permitido
     */
    private function isOriginAllowed(?string $origin, array $allowedOrigins): bool
    {
        if (!$origin) {
            return false;
        }
        
        if (in_array('*', $allowedOrigins)) {
            return true;
        }
        
        if (in_array($origin, $allowedOrigins)) {
            return true;
        }
        
        $patterns = config('cors.allowed_origins_patterns', []);
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $origin)) {
                return true;
            }
        }
        
        return false;
    }
}

