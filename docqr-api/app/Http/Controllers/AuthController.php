<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class AuthController extends Controller
{
    /**
     * Login de usuario
     */
    public function login(Request $request): JsonResponse
    {
        // Validamos que envíen los datos
        $request->validate([
            'username' => 'required|string', // El frontend manda "username", lo aceptamos
            'password' => 'required|string',
        ]);

        try {
            // 1. CORRECCIÓN CRÍTICA: Buscamos por EMAIL, no por username.
            // Asumimos que el usuario escribe su correo en el campo de usuario.
            // Quitamos 'username' del select porque esa columna NO existe en DB.
            $user = User::select(['id', 'name', 'email', 'password', 'role', 'is_active'])
                ->where('email', $request->username) // <--- Aquí está el truco: mapeamos username -> email
                ->first();

            // Verificamos si el usuario existe y está activo
            if (!$user || ($user->is_active === 0 || $user->is_active === false)) {
                 // Nota: Si no usas el campo is_active en DB, borra la condición del paréntesis
                return response()->json([
                    'success' => false,
                    'message' => 'Credenciales incorrectas o usuario inactivo'
                ], 401);
            }

            // 2. Verificar contraseña
            if (!Hash::check($request->password, $user->password)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuario o contraseña incorrectos'
                ], 401);
            }

            // 3. Iniciar sesión (si usas sesiones) o generar token
            Auth::login($user);
            
            // Generar token de Sanctum (Opcional, pero recomendado para APIs)
            // $token = $user->createToken('auth_token')->plainTextToken;

            return response()->json([
                'success' => true,
                'message' => 'Login exitoso',
                'data' => [
                    'user' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'role' => $user->role,
                        // 'username' => $user->username, // <--- ELIMINADO PARA EVITAR ERROR
                    ],
                    // 'token' => $token // Descomenta si usas tokens
                ]
            ], 200);

        } catch (\Exception $e) {
            Log::error('Error en login: ' . $e->getMessage());
            
            // Respuesta genérica para no dar pistas en producción, pero útil si tienes debug activado
            return response()->json([
                'success' => false,
                'message' => 'Error interno al iniciar sesión',
                'debug_error' => env('APP_DEBUG') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Obtener usuario actual autenticado
     */
    public function me(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuario no autenticado'
                ], 401);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'user' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'role' => $user->role,
                        // 'username' eliminado
                    ]
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al verificar sesión'
            ], 500);
        }
    }

    /**
     * Alias para user
     */
    public function user(Request $request): JsonResponse
    {
        return $this->me($request);
    }

    /**
     * Logout
     */
    public function logout(Request $request): JsonResponse
    {
        try {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return response()->json([
                'success' => true,
                'message' => 'Sesión cerrada exitosamente'
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al cerrar sesión'
            ], 500);
        }
    }

    /**
     * Actualizar perfil
     */
    public function updateProfile(Request $request): JsonResponse
    {
        $user = Auth::user();
        if (!$user) return response()->json(['success'=>false], 401);

        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255|unique:users,email,'.$user->id,
        ]);

        $user->name = $request->input('name');
        if ($request->has('email')) {
            $user->email = $request->input('email');
        }
        $user->save();

        return response()->json([
            'success' => true,
            'message' => 'Perfil actualizado',
            'data' => ['user' => $user]
        ]);
    }

    /**
     * Actualizar password
     */
    public function updatePassword(Request $request): JsonResponse
    {
        $user = Auth::user();
        if (!$user) return response()->json(['success'=>false], 401);

        $request->validate([
            'current_password' => 'required',
            'new_password' => 'required|min:6|confirmed', // 'confirmed' busca new_password_confirmation
        ]);

        if (!Hash::check($request->input('current_password'), $user->password)) {
            return response()->json(['success'=>false, 'message'=>'Contraseña actual incorrecta'], 422);
        }

        $user->password = Hash::make($request->input('new_password'));
        $user->save();

        return response()->json(['success'=>true, 'message'=>'Contraseña actualizada']);
    }
}