<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Models\User;

class AuthMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->header('Authorization');
        
        if (!$token) {
            return response()->json([
                'success' => false,
                'message' => 'No autorizado. Token requerido.'
            ], 401);
        }

        // Remover "Bearer " si existe
        $token = str_replace('Bearer ', '', $token);

        try {
            $decoded = base64_decode($token);
            $parts = explode(':', $decoded);
            
            if (count($parts) < 2) {
                return response()->json([
                    'success' => false,
                    'message' => 'Token inválido'
                ], 401);
            }

            $userId = (int)$parts[0];
            $user = User::where('id', $userId)
                ->where('is_active', true)
                ->first();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuario no encontrado o inactivo'
                ], 401);
            }

            // Agregar usuario al request para uso en controladores
            $request->merge(['user' => $user]);
            $request->setUserResolver(function () use ($user) {
                return $user;
            });

            return $next($request);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al verificar token'
            ], 401);
        }
    }
}
