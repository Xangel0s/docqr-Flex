<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthMiddleware
{
    /**
     * Handle an incoming request.
     * 
     * Este middleware usa Sanctum para autenticar usuarios.
     * Primero intenta autenticar con Sanctum, luego verifica que el usuario esté activo.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Intentar autenticar con Sanctum
        // Sanctum verifica automáticamente el token Bearer en el header Authorization
        $user = $request->user('sanctum');
        
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'No autorizado. Token requerido o inválido.'
            ], 401);
        }

        // Verificar que el usuario esté activo
        if (!$user->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'Usuario inactivo'
            ], 403);
        }

        return $next($request);
    }
}
