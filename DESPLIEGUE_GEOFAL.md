# 🚀 Guía de Despliegue - DocQR en Geofal

## 📋 Información del Proyecto

**Frontend:** https://docqr.geofal.com.pe  
**Backend API:** https://docqr-api.geofal.com.pe  
**Dominio Principal:** geofal.com.pe

---

## 🌐 Paso 1: Configuración de Subdominios

### 1.1 Configuración DNS

Agregar los siguientes registros DNS en el panel de tu proveedor de dominio:

```
Tipo    Nombre          Valor                   TTL
A       docqr           [IP_DEL_SERVIDOR]       3600
A       docqr-api       [IP_DEL_SERVIDOR]       3600
CNAME   www.docqr       docqr.geofal.com.pe     3600
```

**Verificar DNS:**
```bash
nslookup docqr.geofal.com.pe
nslookup docqr-api.geofal.com.pe
```

### 1.2 Crear Subdominios en cPanel

1. Ir a **cPanel → Dominios → Subdominios**
2. Crear dos subdominios:

**Subdominio 1 - Frontend:**
- Subdominio: `docqr`
- Dominio: `geofal.com.pe`
- Raíz del documento: `public_html/docqr`

**Subdominio 2 - Backend API:**
- Subdominio: `docqr-api`
- Dominio: `geofal.com.pe`
- Raíz del documento: `public_html/docqr-api/public`

---

## 🔒 Paso 2: Configuración de SSL/HTTPS

### 2.1 Instalación de Certificados SSL

**Opción A: SSL Gratuito de cPanel (Let's Encrypt)**
1. Ir a **cPanel → Seguridad → SSL/TLS**
2. Seleccionar **Administrar sitios SSL**
3. Buscar `docqr.geofal.com.pe`
4. Click en **Instalar y administrar SSL para su sitio**
5. Repetir para `docqr-api.geofal.com.pe`

**Opción B: Certbot (VPS/Servidor Dedicado)**
```bash
sudo certbot --apache -d docqr.geofal.com.pe -d www.docqr.geofal.com.pe
sudo certbot --apache -d docqr-api.geofal.com.pe
```

### 2.2 Forzar HTTPS

Crear archivo `.htaccess` en la raíz de cada subdominio:

```apache
RewriteEngine On
RewriteCond %{HTTPS} off
RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
```

---

## 📁 Paso 3: Estructura de Directorios

```
public_html/
│
├── docqr/                              ← Frontend (docqr.geofal.com.pe)
│   ├── index.html
│   ├── *.js, *.css
│   ├── assets/
│   └── .htaccess
│
└── docqr-api/                          ← Backend (docqr-api.geofal.com.pe)
    ├── public/                         ← Raíz web del subdominio
    │   ├── index.php
    │   └── .htaccess
    ├── app/
    ├── config/
    ├── database/
    ├── routes/
    ├── storage/                        ← ¡Permisos 775!
    │   ├── app/
    │   │   ├── pdfs/
    │   │   ├── qrs/
    │   │   └── final_pdfs/
    │   └── logs/
    ├── bootstrap/cache/                ← ¡Permisos 775!
    ├── .env                            ← ¡CRÍTICO!
    ├── .user.ini                       ← Configuración PHP
    └── composer.json
```

---

## ⚙️ Paso 4: Configuración de PHP

### 4.1 Crear archivo .user.ini

En `public_html/docqr-api/`, crear `.user.ini`:

```ini
upload_max_filesize = 500M
post_max_size = 510M
memory_limit = 1024M
max_execution_time = 600
max_input_time = 600
max_input_vars = 3000
file_uploads = On
allow_url_fopen = On
```

### 4.2 Configurar desde cPanel

1. Ir a **Select PHP Version** o **MultiPHP INI Editor**
2. Seleccionar el dominio `docqr-api.geofal.com.pe`
3. Modificar:
   - `upload_max_filesize` → `500M`
   - `post_max_size` → `510M`
   - `memory_limit` → `1024M`
   - `max_execution_time` → `600`
   - `max_input_time` → `600`

### 4.3 Verificar Configuración

Subir y ejecutar: `https://docqr-api.geofal.com.pe/verificar_produccion.php`

---

## 🗄️ Paso 5: Configuración de Base de Datos

### 5.1 Crear Base de Datos en cPanel

1. Ir a **MySQL® Databases**
2. Crear base de datos: `geofal_docqr`
3. Crear usuario: `geofal_docqr_user`
4. Asignar todos los privilegios al usuario

### 5.2 Importar Estructura (Si aplica)

```bash
# Desde terminal SSH
cd public_html/docqr-api
php artisan migrate --force
```

O importar SQL directamente en phpMyAdmin.

---

## 🔧 Paso 6: Configuración del Backend

### 6.1 Copiar y Configurar .env

```bash
cd public_html/docqr-api
cp .env.production .env
nano .env
```

Modificar estos valores:
```env
APP_KEY=                                    # Generar con: php artisan key:generate
DB_DATABASE=geofal_docqr
DB_USERNAME=geofal_docqr_user
DB_PASSWORD=[tu_password_aqui]
```

### 6.2 Instalar Dependencias

```bash
cd public_html/docqr-api
composer install --no-dev --optimize-autoloader
```

### 6.3 Ejecutar Migraciones

```bash
php artisan migrate --force
```

### 6.4 Generar Clave de Aplicación

```bash
php artisan key:generate
```

### 6.5 Optimizar para Producción

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan optimize
```

### 6.6 Configurar Permisos

```bash
chmod -R 775 storage bootstrap/cache
chown -R [usuario]:[usuario] storage bootstrap/cache
```

En cPanel, reemplazar `[usuario]` con tu usuario de cPanel.

### 6.7 Crear Usuario Administrador

```bash
php database/scripts/crear_usuario_admin.php
```

Credenciales por defecto: `admin` / `admin123`

---

## 🎨 Paso 7: Despliegue del Frontend

### 7.1 Compilar Frontend Localmente

```bash
cd docqr-frontend
npm install
npm run build --prod
```

Los archivos compilados estarán en: `dist/docqr-frontend/`

### 7.2 Subir Archivos al Servidor

Subir TODO el contenido de `dist/docqr-frontend/` a `public_html/docqr/`

### 7.3 Crear .htaccess para Angular

En `public_html/docqr/.htaccess`:

```apache
<IfModule mod_rewrite.c>
  RewriteEngine On
  RewriteBase /
  
  # Si el archivo/carpeta no existe, redirigir a index.html
  RewriteCond %{REQUEST_FILENAME} !-f
  RewriteCond %{REQUEST_FILENAME} !-d
  RewriteRule . /index.html [L]
</IfModule>

# Seguridad
<IfModule mod_headers.c>
  Header set X-Content-Type-Options "nosniff"
  Header set X-Frame-Options "SAMEORIGIN"
  Header set X-XSS-Protection "1; mode=block"
</IfModule>

# Compresión
<IfModule mod_deflate.c>
  AddOutputFilterByType DEFLATE text/html text/plain text/xml text/css text/javascript application/javascript application/json
</IfModule>

# Cache de archivos estáticos
<IfModule mod_expires.c>
  ExpiresActive On
  ExpiresByType image/jpg "access plus 1 year"
  ExpiresByType image/jpeg "access plus 1 year"
  ExpiresByType image/gif "access plus 1 year"
  ExpiresByType image/png "access plus 1 year"
  ExpiresByType image/svg+xml "access plus 1 year"
  ExpiresByType text/css "access plus 1 month"
  ExpiresByType application/javascript "access plus 1 month"
</IfModule>
```

---

## 🔄 Paso 8: Configurar Cron Jobs

### 8.1 Eliminar Documentos Antiguos

En cPanel → **Cron Jobs**:

**Configuración:**
- Minuto: `0`
- Hora: `2`
- Día: `*`
- Mes: `*`
- Día de la semana: `*`

**Comando:**
```bash
/usr/bin/php /home/[USUARIO_CPANEL]/public_html/docqr-api/artisan documents:force-delete-old 30
```

Reemplazar `[USUARIO_CPANEL]` con tu usuario de cPanel.

---

## ✅ Paso 9: Verificación Post-Despliegue

### 9.1 Checklist de Verificación

Ejecutar `verificar_produccion.php`:
```
https://docqr-api.geofal.com.pe/verificar_produccion.php
```

### 9.2 Pruebas Funcionales

1. ✅ Acceder a https://docqr.geofal.com.pe
2. ✅ Login con `admin` / `admin123`
3. ✅ Subir PDF pequeño (<5MB)
4. ✅ Subir PDF grande (>100MB)
5. ✅ Adjuntar PDF a documento
6. ✅ Editar posición de QR
7. ✅ Guardar y recargar (F5) → ¿Persiste?
8. ✅ Eliminar documento → ¿Desaparece?
9. ✅ Descargar código QR
10. ✅ Ver PDF final

### 9.3 Verificar Logs

```bash
tail -f public_html/docqr-api/storage/logs/laravel.log
```

---

## 🔧 Solución de Problemas

### Error 500 - Internal Server Error

**Verificar:**
1. Permisos de `storage/` y `bootstrap/cache/` (775)
2. Archivo `.env` existe y está configurado
3. `APP_KEY` está generado
4. Ver logs: `storage/logs/laravel.log`

**Solución:**
```bash
chmod -R 775 storage bootstrap/cache
php artisan config:clear
php artisan cache:clear
```

### Error "upload_max_filesize exceeded"

**Verificar:**
```bash
php -i | grep upload_max_filesize
```

**Debe mostrar:** `500M`

**Solución:**
1. Verificar `.user.ini`
2. Configurar en cPanel MultiPHP INI Editor
3. Contactar soporte del hosting si no funciona

### Error de CORS

**Verificar `.env`:**
```env
CORS_ALLOWED_ORIGINS=https://docqr.geofal.com.pe,https://www.docqr.geofal.com.pe
```

**Solución:**
```bash
php artisan config:cache
```

### Base de Datos No Conecta

**Verificar `.env`:**
- `DB_HOST=127.0.0.1` (no `localhost`)
- Credenciales correctas

**Probar conexión:**
```bash
mysql -h 127.0.0.1 -u geofal_docqr_user -p geofal_docqr
```

### Frontend Muestra 404

**Verificar:**
1. `.htaccess` existe en `public_html/docqr/`
2. `mod_rewrite` está activo
3. Permisos de lectura en todos los archivos

---

## 🔄 Actualización del Sistema

### Actualizar Backend

```bash
cd public_html/docqr-api
git pull origin main                          # Si usas Git
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

### Actualizar Frontend

```bash
# Localmente:
cd docqr-frontend
git pull origin main
npm install
npm run build --prod

# Subir archivos de dist/docqr-frontend/ al servidor
```

---

## 📞 Soporte y Contacto

**Logs del Sistema:**
- Backend: `public_html/docqr-api/storage/logs/laravel.log`
- Apache: Ver en cPanel → Errors

**Herramientas de Diagnóstico:**
- Script de verificación: `verificar_produccion.php`
- Configuración PHP: `phpinfo()`
- Estado del servidor: `top`, `htop`

---

## 🎯 Resumen de URLs

| Servicio | URL |
|----------|-----|
| Frontend Producción | https://docqr.geofal.com.pe |
| Backend API | https://docqr-api.geofal.com.pe/api |
| Verificación | https://docqr-api.geofal.com.pe/verificar_produccion.php |
| Login Admin | admin / admin123 |

---

**✅ Sistema listo para producción en Geofal!**

