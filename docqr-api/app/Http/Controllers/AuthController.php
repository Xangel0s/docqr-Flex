<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class AuthController extends Controller
{
    /**
     * Login de usuario
     */
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'username' => 'required|string',
            'password' => 'required|string',
        ]);

        try {
            // Optimización: usar índice en username e is_active
            // La consulta es rápida incluso con múltiples usuarios simultáneos
            $user = User::where('username', $request->username)
                ->where('is_active', true)
                ->first();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuario o contraseña incorrectos'
                ], 401);
            }

            if (!Hash::check($request->password, $user->password)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuario o contraseña incorrectos'
                ], 401);
            }

            // Crear token simple (en producción usar JWT o Sanctum)
            $token = base64_encode($user->id . ':' . $user->username . ':' . time());

            Log::info('Usuario autenticado', [
                'user_id' => $user->id,
                'username' => $user->username
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Login exitoso',
                'data' => [
                    'user' => [
                        'id' => $user->id,
                        'username' => $user->username,
                        'name' => $user->name,
                        'email' => $user->email,
                        'role' => $user->role,
                    ],
                    'token' => $token,
                ]
            ], 200);

        } catch (\Exception $e) {
            Log::error('Error en login: ' . $e->getMessage(), [
                'username' => $request->username,
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al iniciar sesión'
            ], 500);
        }
    }

    /**
     * Verificar token y obtener usuario actual
     * Optimizado para múltiples usuarios simultáneos
     */
    public function me(Request $request): JsonResponse
    {
        try {
            $user = $this->getUserFromToken($request);

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Token inválido'
                ], 401);
            }

            // Retornar datos del usuario sin consultas adicionales
            return response()->json([
                'success' => true,
                'data' => [
                    'user' => [
                        'id' => $user->id,
                        'username' => $user->username,
                        'name' => $user->name,
                        'email' => $user->email,
                        'role' => $user->role,
                    ]
                ]
            ], 200);

        } catch (\Exception $e) {
            Log::error('Error en verificación de sesión: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al verificar sesión'
            ], 500);
        }
    }

    /**
     * Logout
     */
    public function logout(Request $request): JsonResponse
    {
        // En un sistema simple, el logout se maneja en el frontend
        // Eliminando el token del almacenamiento local
        return response()->json([
            'success' => true,
            'message' => 'Sesión cerrada exitosamente'
        ], 200);
    }

    /**
     * Obtener usuario desde token
     */
    private function getUserFromToken(Request $request): ?User
    {
        $token = $request->header('Authorization');
        
        if (!$token) {
            return null;
        }

        // Remover "Bearer " si existe
        $token = str_replace('Bearer ', '', $token);

        try {
            $decoded = base64_decode($token);
            $parts = explode(':', $decoded);
            
            if (count($parts) < 2) {
                return null;
            }

            $userId = (int)$parts[0];
            $user = User::where('id', $userId)
                ->where('is_active', true)
                ->first();

            return $user;

        } catch (\Exception $e) {
            return null;
        }
    }
}
