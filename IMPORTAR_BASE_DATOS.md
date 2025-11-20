# 🗄️ Cómo Importar la Base de Datos - DocQR

## Problema Resuelto

El archivo SQL original tenía un error de formato. He creado **2 archivos corregidos**:

1. `database_structure.sql` ✅ (Corregido)
2. `database_manual.sql` ✅ (Versión limpia y verificada)

---

## 📋 Método 1: phpMyAdmin (RECOMENDADO)

### Paso a Paso:

1. **Entrar a phpMyAdmin:**
   - Ir a cPanel → phpMyAdmin

2. **Seleccionar Base de Datos:**
   - Click en `geofal_docqr` (lado izquierdo)

3. **Importar:**
   - Click en pestaña **"Importar"** (arriba)
   - Click en **"Seleccionar archivo"**
   - Elegir: `database_manual.sql`
   - Scroll hacia abajo
   - Click en **"Continuar"**

4. **Verificar:**
   - Debe decir: "Importación finalizada con éxito"
   - Ir a pestaña **"Estructura"**
   - Debes ver estas tablas:
     - ✅ users
     - ✅ qr_files
     - ✅ cache
     - ✅ cache_locks
     - ✅ migrations

5. **Verificar Usuario Admin:**
   - Click en tabla `users`
   - Click en pestaña **"Examinar"**
   - Debe aparecer: **Administrador | admin@docqr.com**

---

## 📋 Método 2: SSH/Terminal

```bash
# Conectar por SSH
ssh usuario@tuservidor.com

# Ir al directorio donde está el archivo SQL
cd /home/USUARIO/

# Subir el archivo (si no lo has hecho)
# Puedes usar FileZilla o cPanel File Manager

# Importar
mysql -h 127.0.0.1 -u geofal_docqr_user -p geofal_docqr < database_manual.sql

# Introducir password cuando lo pida

# Verificar
mysql -h 127.0.0.1 -u geofal_docqr_user -p geofal_docqr -e "SHOW TABLES;"

# Debe mostrar:
# cache
# cache_locks
# migrations
# qr_files
# users

# Verificar usuario admin
mysql -h 127.0.0.1 -u geofal_docqr_user -p geofal_docqr -e "SELECT id, name, email FROM users;"

# Debe mostrar:
# 1 | Administrador | admin@docqr.com
```

---

## 📋 Método 3: Dejar que Laravel lo haga (ALTERNATIVA)

Si prefieres que Laravel cree todo automáticamente:

```bash
# 1. NO importar ningún SQL
# 2. Asegurarte de que .env está configurado correctamente
# 3. Ejecutar migraciones:

cd /home/USUARIO/public_html/docqr-api
./php81 artisan migrate --force

# 4. Crear usuario admin:
./php81 database/scripts/crear_usuario_admin.php

# ¡Listo!
```

**Ventaja:** Laravel crea todo automáticamente  
**Desventaja:** Si hay algún error de migración, puede fallar

---

## 🔍 Verificación de Importación Exitosa

### Checklist:

```sql
-- 1. Verificar tablas (deben ser 5)
SHOW TABLES;

-- 2. Verificar estructura de users
DESCRIBE users;

-- 3. Verificar estructura de qr_files
DESCRIBE qr_files;

-- 4. Verificar usuario admin
SELECT * FROM users WHERE email = 'admin@docqr.com';

-- 5. Verificar migraciones (deben ser 10)
SELECT COUNT(*) FROM migrations;
```

**Resultado esperado:**
- ✅ 5 tablas creadas
- ✅ 1 usuario admin
- ✅ 10 migraciones registradas

---

## 🚨 Solución de Errores Comunes

### Error: "Tabla ya existe"

**Solución:**
```sql
-- Eliminar tablas existentes (CUIDADO, perderás datos)
DROP TABLE IF EXISTS cache_locks;
DROP TABLE IF EXISTS cache;
DROP TABLE IF EXISTS qr_files;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS migrations;

-- Luego volver a importar
```

### Error: "Access denied"

**Solución:**
```bash
# Verificar usuario y permisos
mysql -u root -p

# Dentro de MySQL:
SHOW GRANTS FOR 'geofal_docqr_user'@'localhost';

# Debe mostrar: GRANT ALL PRIVILEGES ON geofal_docqr.*

# Si no, otorgar permisos:
GRANT ALL PRIVILEGES ON geofal_docqr.* TO 'geofal_docqr_user'@'localhost';
FLUSH PRIVILEGES;
```

### Error: "Unknown database"

**Solución:**
```sql
-- Crear la base de datos primero
CREATE DATABASE geofal_docqr CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Luego importar
```

### Error al insertar usuario admin: "Duplicate entry"

**Solución:**
```sql
-- El usuario ya existe, eliminarlo primero
DELETE FROM users WHERE id = 1;

-- Luego volver a ejecutar el INSERT
INSERT INTO users (id, name, email, password, is_active, created_at, updated_at) 
VALUES (1, 'Administrador', 'admin@docqr.com', '$2y$12$LDmkQVjPzPE/8Z3WqXaJU.kC0VRw9oN4nMJxGXh1ZxY/4a9r0Pxeu', 1, NOW(), NOW());
```

---

## 📊 Datos del Usuario Admin

**Email:** `admin@docqr.com`  
**Password:** `admin123`  
**Hash:** `$2y$12$LDmkQVjPzPE/8Z3WqXaJU.kC0VRw9oN4nMJxGXh1ZxY/4a9r0Pxeu`

**⚠️ IMPORTANTE:** Cambiar la contraseña después del primer login

---

## 🎯 Después de Importar

1. **Verificar en el navegador:**
   ```
   https://docqr-api.geofal.com.pe/verificar_produccion.php
   ```
   - Debe mostrar: "Base de datos conectada ✅"

2. **Intentar login:**
   ```
   https://docqr.geofal.com.pe
   Usuario: admin
   Password: admin123
   ```

3. **Si funciona:** ¡Listo! ✅

4. **Si no funciona:** Revisar `.env`:
   ```env
   DB_HOST=127.0.0.1
   DB_DATABASE=geofal_docqr
   DB_USERNAME=geofal_docqr_user
   DB_PASSWORD=tu_password
   ```

---

## 💡 Consejo

**Usa `database_manual.sql`** - Es más limpio y está verificado que funciona correctamente.

---

**✅ Después de importar la base de datos, continúa con `INSTALACION_COMPLETA_CPANEL.md`**

