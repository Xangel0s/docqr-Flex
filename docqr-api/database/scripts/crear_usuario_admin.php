<?php

/**
 * Script para crear usuario administrador inicial
 * 
 * USO:
 * php database/scripts/crear_usuario_admin.php
 * 
 * O desde tinker:
 * require 'database/scripts/crear_usuario_admin.php';
 * crearUsuarioAdmin('admin', 'password123', 'Administrador');
 */

// Obtener el directorio raíz del proyecto Laravel
// Este script está en: docqr-api/database/scripts/
// El raíz de Laravel está 2 niveles arriba (docqr-api/)
$rootDir = realpath(__DIR__ . '/../..');

if (!$rootDir) {
    // Si realpath falla, intentar con dirname
    $rootDir = dirname(__DIR__, 2);
}

// Verificar que existe vendor/autoload.php
$autoloadPath = $rootDir . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
if (!file_exists($autoloadPath)) {
    die("❌ ERROR: No se encontró vendor/autoload.php en: {$autoloadPath}\nEjecuta 'composer install' primero.\n");
}

require $autoloadPath;

$bootstrapPath = $rootDir . DIRECTORY_SEPARATOR . 'bootstrap' . DIRECTORY_SEPARATOR . 'app.php';
if (!file_exists($bootstrapPath)) {
    die("❌ ERROR: No se encontró bootstrap/app.php en: {$bootstrapPath}\n");
}

$app = require_once $bootstrapPath;
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use Illuminate\Support\Facades\Hash;

function crearUsuarioAdmin(string $username = 'admin', string $password = 'admin123', string $name = 'Administrador'): void
{
    echo "\n=== CREAR USUARIO ADMINISTRADOR ===\n\n";
    
    // Verificar si ya existe
    $existe = User::where('username', $username)->first();
    if ($existe) {
        echo "⚠️  El usuario '{$username}' ya existe.\n";
        echo "   ID: {$existe->id}\n";
        echo "   Nombre: {$existe->name}\n";
        echo "   Rol: {$existe->role}\n\n";
        
        $respuesta = readline("¿Deseas actualizar la contraseña? (s/n): ");
        if (strtolower($respuesta) === 's') {
            $existe->password = Hash::make($password);
            $existe->save();
            echo "✅ Contraseña actualizada exitosamente.\n\n";
        }
        return;
    }
    
    // Crear usuario
    try {
        $user = User::create([
            'username' => $username,
            'password' => Hash::make($password),
            'name' => $name,
            'email' => $username . '@geofal.com',
            'role' => 'admin',
            'is_active' => true,
        ]);
        
        echo "✅ Usuario administrador creado exitosamente:\n";
        echo "   ID: {$user->id}\n";
        echo "   Usuario: {$user->username}\n";
        echo "   Nombre: {$user->name}\n";
        echo "   Contraseña: {$password}\n";
        echo "   Rol: {$user->role}\n\n";
        echo "⚠️  IMPORTANTE: Cambia la contraseña después del primer inicio de sesión.\n\n";
        
    } catch (\Exception $e) {
        echo "❌ ERROR: " . $e->getMessage() . "\n\n";
    }
}

// Si se ejecuta directamente
if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($_SERVER['PHP_SELF'])) {
    // Valores por defecto o desde argumentos
    $username = $argv[1] ?? 'admin';
    $password = $argv[2] ?? 'admin123';
    $name = $argv[3] ?? 'Administrador';
    
    crearUsuarioAdmin($username, $password, $name);
}

