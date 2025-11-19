<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class AuthController extends Controller
{
    /**
     * Login de usuario usando Laravel Sanctum
     */
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'username' => 'required|string',
            'password' => 'required|string',
        ]);

        try {
            // Buscar usuario activo por username
            $user = User::where('username', $request->username)
                ->where('is_active', true)
                ->first();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuario o contraseña incorrectos'
                ], 401);
            }

            // Verificar contraseña
            if (!Hash::check($request->password, $user->password)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuario o contraseña incorrectos'
                ], 401);
            }

            // Borrar tokens anteriores para no acumular basura
            $user->tokens()->delete();

            // Crear nuevo token con Sanctum
            $token = $user->createToken('auth_token')->plainTextToken;

            Log::info('Usuario autenticado con Sanctum', [
                'user_id' => $user->id,
                'username' => $user->username
            ]);

            // Retornar respuesta compatible con el frontend existente
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
                    // También incluir access_token para compatibilidad
                    'access_token' => $token,
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
     * Obtener usuario actual autenticado (usando Sanctum)
     */
    public function me(Request $request): JsonResponse
    {
        try {
            // Sanctum automáticamente autentica el usuario desde el token
            $user = $request->user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Token inválido o usuario no autenticado'
                ], 401);
            }

            // Retornar datos del usuario
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
     * Alias para compatibilidad con frontend
     * @deprecated Usar me() en su lugar
     */
    public function user(Request $request): JsonResponse
    {
        return $this->me($request);
    }

    /**
     * Logout - Eliminar token actual usando Sanctum
     */
    public function logout(Request $request): JsonResponse
    {
        try {
            // Obtener usuario autenticado
            $user = $request->user();
        
            if ($user) {
                // Eliminar el token actual
                $user->currentAccessToken()->delete();

                Log::info('Usuario cerró sesión', [
                    'user_id' => $user->id,
                    'username' => $user->username
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Sesión cerrada exitosamente'
            ], 200);

        } catch (\Exception $e) {
            Log::error('Error en logout: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al cerrar sesión'
            ], 500);
        }
    }
}
