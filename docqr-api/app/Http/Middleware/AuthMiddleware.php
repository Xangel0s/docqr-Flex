<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class AuthMiddleware
{
    /**
     * Handle an incoming request.
     * 
     * Este middleware usa sesiones para autenticar usuarios.
     * Verifica que el usuario esté autenticado mediante sesión y activo.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Verificar autenticación por sesión
        if (!Auth::check()) {
            return response()->json([
                'success' => false,
                'message' => 'No autorizado. Sesión requerida.'
            ], 401);
        }

        $user = Auth::user();

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
