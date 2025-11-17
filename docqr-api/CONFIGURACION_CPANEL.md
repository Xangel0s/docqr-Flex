# 🚀 Guía de Configuración para cPanel

## 📋 Checklist de Configuración

### ✅ 1. Versión de PHP

**Ubicación:** cPanel → Select PHP Version

**Requisitos:**
- ✅ PHP 8.1 o superior (recomendado: PHP 8.2 o 8.3)
- ✅ Extensiones necesarias (ver sección 2)

**Pasos:**
1. Ir a "Select PHP Version"
2. Seleccionar PHP 8.1 o superior
3. Guardar cambios

---

### ✅ 2. Extensiones PHP Requeridas

**Ubicación:** cPanel → Select PHP Version → Extensions

**Extensiones OBLIGATORIAS:**
```
✅ GD (para generar códigos QR)
✅ Imagick o GD (para procesar imágenes)
✅ MBString (para manipulación de strings)
✅ OpenSSL (para seguridad)
✅ PDO (para base de datos)
✅ PDO_MySQL (para MySQL)
✅ Zip (para compresión)
✅ Fileinfo (para validar tipos de archivo)
✅ XML (para procesamiento XML)
✅ JSON (para APIs)
```

**Pasos:**
1. En "Select PHP Version", hacer clic en "Extensions"
2. Activar todas las extensiones listadas arriba
3. Guardar cambios

---

### ✅ 3. Límites de PHP

**Ubicación:** cPanel → Select PHP Version → Options

**Configuraciones Recomendadas:**
```
upload_max_filesize = 50M        (PDFs pueden ser grandes)
post_max_size = 50M              (debe ser >= upload_max_filesize)
memory_limit = 256M              (para procesar PDFs grandes)
max_execution_time = 300         (5 minutos para procesar PDFs)
max_input_time = 300
```

**Pasos:**
1. En "Select PHP Version", hacer clic en "Options"
2. Configurar los valores arriba
3. Guardar cambios

**Alternativa (si no puedes editar):**
Crear archivo `.user.ini` en la raíz del proyecto:
```ini
upload_max_filesize = 50M
post_max_size = 50M
memory_limit = 256M
max_execution_time = 300
max_input_time = 300
```

---

### ✅ 4. Permisos de Carpetas

**Ubicación:** cPanel → File Manager

**Carpetas a Configurar:**
```
storage/app/              → 755 (directorio)
storage/app/uploads/      → 755 (directorio)
storage/app/final/        → 755 (directorio)
storage/app/qrcodes/      → 755 (directorio)
storage/framework/        → 755 (directorio)
storage/logs/             → 755 (directorio)
bootstrap/cache/          → 755 (directorio)
```

**Pasos:**
1. Ir a File Manager
2. Navegar a cada carpeta
3. Click derecho → Change Permissions
4. Marcar: Owner (Read, Write, Execute), Group (Read, Execute), Public (Read, Execute)
5. Aplicar a subdirectorios si es necesario

**Comando SSH (si tienes acceso):**
```bash
cd /home/usuario/public_html/docqr-api
chmod -R 755 storage bootstrap/cache
chmod -R 775 storage/app/uploads storage/app/final storage/app/qrcodes
```

---

### ✅ 5. Archivo .env (Variables de Entorno)

**Ubicación:** cPanel → File Manager → `docqr-api/.env`

**Configuración Mínima:**
```env
APP_NAME="DocQR"
APP_ENV=production
APP_KEY=base64:TU_CLAVE_AQUI
APP_DEBUG=false
APP_URL=https://tudominio.com

DB_CONNECTION=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=nombre_base_datos
DB_USERNAME=usuario_bd
DB_PASSWORD=contraseña_bd

FILESYSTEM_DISK=local
```

**Pasos:**
1. Copiar `.env.example` a `.env` (si no existe)
2. Editar `.env` con los valores de tu servidor
3. Generar `APP_KEY`: `php artisan key:generate`

**⚠️ IMPORTANTE:**
- `APP_DEBUG=false` en producción
- `APP_URL` debe ser HTTPS si tienes SSL
- No compartir `.env` públicamente

---

### ✅ 6. Base de Datos

**Ubicación:** cPanel → MySQL Databases

**Pasos:**
1. Crear base de datos:
   - Ir a "MySQL Databases"
   - Crear nueva base de datos (ej: `docqr_db`)
   - Crear usuario y contraseña
   - Asignar usuario a la base de datos con "ALL PRIVILEGES"

2. Importar estructura:
   - Ir a phpMyAdmin
   - Seleccionar la base de datos creada
   - Importar el archivo `database/CREAR_BASE_DATOS_COMPLETA.sql`

3. Verificar en `.env`:
   ```env
   DB_DATABASE=docqr_db
   DB_USERNAME=usuario_bd
   DB_PASSWORD=contraseña_bd
   ```

---

### ✅ 7. Estructura de Carpetas (Crear si no existen)

**Ubicación:** cPanel → File Manager

**Carpetas a Crear:**
```
storage/app/uploads/
storage/app/final/
storage/app/qrcodes/
storage/framework/cache/
storage/framework/sessions/
storage/framework/views/
storage/logs/
bootstrap/cache/
```

**Pasos:**
1. En File Manager, navegar a `storage/app/`
2. Crear carpetas: `uploads`, `final`, `qrcodes`
3. Repetir para otras carpetas necesarias

---

### ✅ 8. Configuración de Apache (si aplica)

**Ubicación:** cPanel → File Manager → `public/.htaccess`

**Verificar que existe:**
```apache
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteRule ^ index.php [L]
</IfModule>
```

**Si no funciona, agregar en `.htaccess` de la raíz:**
```apache
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteCond %{REQUEST_URI} !^/public/
    RewriteRule ^(.*)$ /public/$1 [L]
</IfModule>
```

---

### ✅ 9. SSL/HTTPS (Recomendado)

**Ubicación:** cPanel → SSL/TLS Status

**Pasos:**
1. Instalar certificado SSL (Let's Encrypt es gratuito)
2. Forzar HTTPS en `.env`:
   ```env
   APP_URL=https://tudominio.com
   ```
3. Verificar que las URLs usen HTTPS

---

### ✅ 10. Optimización de Laravel

**Ejecutar en Terminal SSH (si tienes acceso):**
```bash
cd /home/usuario/public_html/docqr-api
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan optimize
```

**Si NO tienes SSH:**
- Estas optimizaciones se aplican automáticamente en producción
- Laravel detecta `APP_ENV=production` y optimiza

---

### ✅ 11. Verificación Post-Instalación

**Pruebas a Realizar:**

1. **Subir PDF:**
   - Ir a la aplicación
   - Subir un PDF de prueba
   - Verificar que se guarda en `storage/app/uploads/`

2. **Generar QR:**
   - Abrir el editor
   - Verificar que se genera el QR
   - Verificar que se guarda en `storage/app/qrcodes/`

3. **Guardar PDF Final:**
   - Posicionar QR y guardar
   - Verificar que se genera en `storage/app/final/`
   - Verificar que se puede descargar

4. **Logs:**
   - Verificar que se crean logs en `storage/logs/laravel.log`
   - Si hay errores, revisar permisos

---

## ⚠️ Problemas Comunes y Soluciones

### Error: "Permission denied"
**Solución:**
```bash
chmod -R 755 storage bootstrap/cache
chmod -R 775 storage/app/uploads storage/app/final storage/app/qrcodes
```

### Error: "Class not found" o "Extension not loaded"
**Solución:**
- Verificar extensiones PHP en cPanel
- Reiniciar PHP si es necesario

### Error: "Maximum upload size exceeded"
**Solución:**
- Aumentar `upload_max_filesize` y `post_max_size` en PHP Options
- Verificar límites en `.htaccess` si aplica

### Error: "Database connection failed"
**Solución:**
- Verificar credenciales en `.env`
- Verificar que el usuario tiene permisos en la BD
- Verificar que `DB_HOST` es correcto (puede ser `localhost` o `127.0.0.1`)

### PDFs no se descargan
**Solución:**
- Verificar permisos de `storage/app/final/`
- Verificar que las rutas en `config/filesystems.php` son correctas
- Verificar logs en `storage/logs/`

---

## 📝 Resumen de Configuración

### Mínimo Requerido:
1. ✅ PHP 8.1+
2. ✅ Extensiones PHP activadas
3. ✅ Permisos de carpetas (755/775)
4. ✅ Base de datos creada y configurada
5. ✅ Archivo `.env` configurado
6. ✅ `APP_KEY` generado

### Recomendado:
1. ✅ SSL/HTTPS activado
2. ✅ Límites de PHP aumentados
3. ✅ Optimizaciones de Laravel aplicadas
4. ✅ Logs configurados

---

## 🆘 Soporte

Si tienes problemas:
1. Revisar logs en `storage/logs/laravel.log`
2. Verificar permisos de carpetas
3. Verificar configuración de `.env`
4. Contactar al proveedor de hosting si persisten problemas

