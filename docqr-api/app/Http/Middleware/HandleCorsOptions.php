<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware para manejar peticiones OPTIONS (preflight CORS)
 * 
 * Este middleware responde a las peticiones OPTIONS antes de que ngrok las intercepte
 * Es especialmente útil cuando ngrok muestra su página de bienvenida
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
        // Si es una petición OPTIONS (preflight), responder inmediatamente con headers CORS
        if ($request->isMethod('OPTIONS')) {
            $origin = $request->header('Origin');
            
            return response('', 200)
                ->header('Access-Control-Allow-Origin', $origin ?: '*')
                ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, PATCH, OPTIONS, HEAD')
                ->header('Access-Control-Allow-Headers', 'Content-Type, Accept, Authorization, X-Requested-With, X-Frontend-Origin')
                ->header('Access-Control-Expose-Headers', 'Content-Type, Content-Length, Content-Disposition')
                ->header('Access-Control-Max-Age', '86400')
                ->header('Access-Control-Allow-Credentials', 'false');
        }

        return $next($request);
    }
}

