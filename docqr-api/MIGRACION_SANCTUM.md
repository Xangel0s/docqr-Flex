# Migración a Laravel Sanctum - Instrucciones

## ✅ Cambios Realizados

### 1. Modelo User
- ✅ Agregado trait `HasApiTokens` de Sanctum
- **Archivo**: `app/Models/User.php`

### 2. AuthController
- ✅ Actualizado método `login()` para usar Sanctum
  - Borra tokens anteriores antes de crear uno nuevo
  - Crea token con `$user->createToken('auth_token')->plainTextToken`
  - Retorna formato compatible con frontend existente
- ✅ Actualizado método `me()` para usar `$request->user()` de Sanctum
- ✅ Actualizado método `logout()` para eliminar token con `$user->currentAccessToken()->delete()`
- ✅ Agregado método `user()` como alias de `me()` para compatibilidad
- **Archivo**: `app/Http/Controllers/AuthController.php`

### 3. AuthMiddleware
- ✅ Actualizado para usar autenticación de Sanctum
- ✅ Verifica usuario con `$request->user('sanctum')`
- ✅ Mantiene verificación de usuario activo
- **Archivo**: `app/Http/Middleware/AuthMiddleware.php`

## 📋 Pasos Post-Migración

### 1. Ejecutar migración de Sanctum (si no existe)

```bash
php artisan vendor:publish --provider="Laravel\Sanctum\SanctumServiceProvider"
php artisan migrate
```

Esto creará la tabla `personal_access_tokens` necesaria para Sanctum.

### 2. Verificar configuración de Sanctum

Si no existe `config/sanctum.php`, publicarlo:

```bash
php artisan vendor:publish --provider="Laravel\Sanctum\SanctumServiceProvider" --tag="sanctum-config"
```

### 3. Limpiar cachés

```bash
php artisan config:clear
php artisan route:clear
php artisan cache:clear
```

### 4. Verificar que Sanctum esté instalado

```bash
composer show laravel/sanctum
```

Debería mostrar la versión instalada (ej: `laravel/sanctum 4.1.1`).

## 🔍 Verificación

### Probar Login

```bash
curl -X POST https://docqr-api.geofal.com.pe/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"username":"tu_usuario","password":"tu_password"}'
```

**Respuesta esperada**:
```json
{
  "success": true,
  "message": "Login exitoso",
  "data": {
    "user": {
      "id": 1,
      "username": "admin",
      "name": "Administrador",
      "email": "admin@example.com",
      "role": "admin"
    },
    "token": "1|abcdef123456...",
    "access_token": "1|abcdef123456..."
  }
}
```

### Probar Verificación de Usuario

```bash
curl -X GET https://docqr-api.geofal.com.pe/api/auth/me \
  -H "Authorization: Bearer 1|abcdef123456..."
```

**Respuesta esperada**:
```json
{
  "success": true,
  "data": {
    "user": {
      "id": 1,
      "username": "admin",
      "name": "Administrador",
      "email": "admin@example.com",
      "role": "admin"
    }
  }
}
```

### Probar Logout

```bash
curl -X POST https://docqr-api.geofal.com.pe/api/auth/logout \
  -H "Authorization: Bearer 1|abcdef123456..."
```

**Respuesta esperada**:
```json
{
  "success": true,
  "message": "Sesión cerrada exitosamente"
}
```

## ⚠️ Notas Importantes

1. **Tokens Sanctum**: Los tokens generados por Sanctum tienen formato `{id}|{hash}` (ej: `1|abcdef123456...`)

2. **Compatibilidad Frontend**: El frontend ya está configurado para usar el formato de respuesta actual, así que no requiere cambios.

3. **Limpieza de Tokens**: El método `login()` ahora borra todos los tokens anteriores del usuario antes de crear uno nuevo. Esto previene acumulación de tokens en la base de datos.

4. **Seguridad**: Sanctum es más seguro que el sistema de tokens base64 anterior porque:
   - Los tokens se almacenan hasheados en la base de datos
   - Se pueden revocar individualmente
   - Tienen expiración configurable
   - Son únicos y no predecibles

## 🔄 Migración de Tokens Existentes

Si hay usuarios con tokens del sistema anterior (base64), estos dejarán de funcionar después de esta migración. Los usuarios deberán hacer login nuevamente para obtener tokens de Sanctum.

## 📝 Próximos Pasos (Opcional)

1. **Configurar expiración de tokens**: Editar `config/sanctum.php` para establecer `expiration` en minutos
2. **Limpieza automática**: Configurar un job programado para limpiar tokens expirados
3. **Refresh tokens**: Implementar refresh tokens si se requiere

