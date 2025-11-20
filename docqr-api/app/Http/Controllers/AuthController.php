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
     * Login de usuario usando sesiones
     */
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'username' => 'required|string',
            'password' => 'required|string',
        ]);

        try {
            // Optimizar query: seleccionar solo campos necesarios y usar índice
            $user = User::select(['id', 'username', 'name', 'email', 'password', 'role', 'is_active'])
                ->where('username', $request->username)
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

            Auth::login($user);
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
                    ]
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
     * Obtener usuario actual autenticado (usando sesión)
     */
    public function me(Request $request): JsonResponse
    {
        try {
            // Obtener usuario de la sesión
            $user = Auth::user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuario no autenticado'
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
     * Logout - Cerrar sesión
     */
    public function logout(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();
            Auth::logout();

            // Invalidar la sesión
            $request->session()->invalidate();
            $request->session()->regenerateToken();

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

    /**
     * Actualizar perfil del usuario
     */
    public function updateProfile(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuario no autenticado'
                ], 401);
            }

            $request->validate([
                'name' => 'required|string|max:255',
                'email' => 'nullable|email|max:255',
            ]);

            $user->name = $request->input('name');
            if ($request->has('email')) {
                $user->email = $request->input('email');
            }
            $user->save();

            return response()->json([
                'success' => true,
                'message' => 'Perfil actualizado exitosamente',
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

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error de validación',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error al actualizar perfil: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar el perfil'
            ], 500);
        }
    }

    /**
     * Cambiar contraseña del usuario
     */
    public function updatePassword(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuario no autenticado'
                ], 401);
            }

            $request->validate([
                'current_password' => 'required|string',
                'new_password' => 'required|string|min:6',
                'confirm_password' => 'required|string|same:new_password',
            ]);

            // Verificar contraseña actual
            if (!Hash::check($request->input('current_password'), $user->password)) {
                return response()->json([
                    'success' => false,
                    'message' => 'La contraseña actual es incorrecta'
                ], 422);
            }

            // Actualizar contraseña
            $user->password = Hash::make($request->input('new_password'));
            $user->save();

            return response()->json([
                'success' => true,
                'message' => 'Contraseña actualizada exitosamente'
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error de validación',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error al actualizar contraseña: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar la contraseña'
            ], 500);
        }
    }
}
