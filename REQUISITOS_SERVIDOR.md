# 🖥️ Requisitos del Servidor - DocQR Geofal

## ✅ Software Requerido

### 1. PHP 8.1 o superior

**Versión mínima:** PHP 8.1  
**Recomendada:** PHP 8.2

**Verificar versión:**
```bash
php -v
```

### 2. Extensiones PHP Necesarias

#### ✅ Extensiones Obligatorias:

```bash
# En cPanel, ir a: Select PHP Version → Extensions
# Marcar las siguientes:

☑ bcmath          # Operaciones matemáticas precisas
☑ ctype           # Validación de tipos de caracteres
☑ curl            # Peticiones HTTP
☑ dom             # Manipulación de XML/HTML
☑ fileinfo        # Información de archivos
☑ filter          # Filtrado de datos
☑ gd              # Procesamiento de imágenes (QR codes)
☑ hash            # Funciones de hash
☑ json            # Manejo de JSON
☑ mbstring        # Manejo de strings multibyte
☑ openssl         # Encriptación y SSL
☑ pcre            # Expresiones regulares
☑ pdo             # Database abstraction
☑ pdo_mysql       # MySQL driver para PDO
☑ session         # Manejo de sesiones
☑ tokenizer       # Tokenización de PHP
☑ xml             # Procesamiento XML
☑ zip             # Compresión de archivos
```

**Verificar extensiones instaladas:**
```bash
php -m | grep -E "bcmath|ctype|curl|dom|fileinfo|gd|json|mbstring|openssl|pdo|xml|zip"
```

### 3. MySQL/MariaDB

**Versión mínima:**
- MySQL 5.7+
- MariaDB 10.3+

**Verificar versión:**
```bash
mysql --version
```

### 4. Composer

**Versión mínima:** Composer 2.0+

**Verificar:**
```bash
composer --version
```

**Instalar si no existe (cPanel/SSH):**
```bash
cd ~
curl -sS https://getcomposer.org/installer | php
mv composer.phar /usr/local/bin/composer
chmod +x /usr/local/bin/composer
```

### 5. Node.js y NPM (Solo para compilar frontend)

**Solo necesario en tu máquina local, NO en el servidor de producción**

---

## 📋 Lista de Verificación del Servidor

### ✅ Requisitos de Sistema

```bash
# Crear script de verificación
cat > verificar_requisitos.sh << 'EOF'
#!/bin/bash

echo "==================================="
echo "Verificación de Requisitos - DocQR"
echo "==================================="
echo ""

# PHP
echo "PHP:"
php -v | head -n 1
echo ""

# Extensiones PHP
echo "Extensiones PHP críticas:"
for ext in bcmath ctype curl dom fileinfo gd json mbstring openssl pdo pdo_mysql session xml zip; do
    if php -m | grep -q "^$ext$"; then
        echo "✓ $ext"
    else
        echo "✗ $ext - FALTA"
    fi
done
echo ""

# MySQL
echo "MySQL/MariaDB:"
mysql --version
echo ""

# Composer
echo "Composer:"
composer --version 2>/dev/null || echo "✗ Composer no instalado"
echo ""

echo "==================================="
EOF

chmod +x verificar_requisitos.sh
./verificar_requisitos.sh
```

---

## ⚙️ Configuración de PHP para Producción

### 1. Crear archivo .user.ini

En la raíz de `docqr-api/`:

```ini
; Límites de archivos (500MB)
upload_max_filesize = 500M
post_max_size = 510M

; Memoria y tiempo
memory_limit = 1024M
max_execution_time = 600
max_input_time = 600
max_input_vars = 3000

; Errores (producción)
display_errors = Off
display_startup_errors = Off
log_errors = On
error_reporting = E_ALL & ~E_DEPRECATED & ~E_STRICT

; Seguridad
expose_php = Off
allow_url_fopen = On
allow_url_include = Off

; Sesiones
session.cookie_httponly = 1
session.cookie_secure = 1
session.cookie_samesite = None
session.gc_maxlifetime = 28800
```

### 2. Configuración en cPanel

**MultiPHP INI Editor:**
```
upload_max_filesize = 500M
post_max_size = 510M
memory_limit = 1024M
max_execution_time = 600
max_input_time = 600
```

---

## 🔒 Permisos del Backend

### Comandos para Establecer Permisos Correctos

```bash
cd /home/USUARIO/public_html/docqr-api

# Dar permisos al usuario web (www-data o tu usuario de cPanel)
chown -R USUARIO:USUARIO .

# Permisos estándar para archivos y directorios
find . -type f -exec chmod 644 {} \;
find . -type d -exec chmod 755 {} \;

# Permisos especiales para storage y bootstrap/cache
chmod -R 775 storage
chmod -R 775 bootstrap/cache

# Scripts ejecutables
chmod +x php81
chmod +x setup-cpanel.sh

# Artisan ejecutable
chmod +x artisan

# Verificar
ls -la storage
ls -la bootstrap/cache
```

### Estructura de Permisos:

```
docqr-api/
├── app/                    → 755 (directorios) / 644 (archivos)
├── bootstrap/
│   └── cache/             → 775 (ESCRIBIBLE)
├── config/                → 755 / 644
├── database/              → 755 / 644
├── public/                → 755 / 644
├── routes/                → 755 / 644
├── storage/               → 775 (ESCRIBIBLE)
│   ├── app/
│   │   ├── pdfs/         → 775 (ESCRIBIBLE)
│   │   ├── qrs/          → 775 (ESCRIBIBLE)
│   │   └── final_pdfs/   → 775 (ESCRIBIBLE)
│   ├── framework/
│   │   ├── cache/        → 775 (ESCRIBIBLE)
│   │   ├── sessions/     → 775 (ESCRIBIBLE)
│   │   └── views/        → 775 (ESCRIBIBLE)
│   └── logs/             → 775 (ESCRIBIBLE)
├── vendor/                → 755 / 644
├── .env                   → 600 (SOLO PROPIETARIO)
├── artisan                → 755 (EJECUTABLE)
├── php81                  → 755 (EJECUTABLE)
└── setup-cpanel.sh        → 755 (EJECUTABLE)
```

---

## 🍪 Configuración de Cookies y Sesiones

### 1. En el archivo .env (Backend)

```env
# Sesiones HTTPS (Producción)
SESSION_SECURE_COOKIE=true
SESSION_SAME_SITE=None
SESSION_LIFETIME=480

# Dominio de sesión (opcional, ajustar si es necesario)
SESSION_DOMAIN=.geofal.com.pe
```

### 2. En config/session.php (Ya configurado)

```php
'secure' => env('SESSION_SECURE_COOKIE', false),
'same_site' => env('SESSION_SAME_SITE', 'lax'),
'http_only' => true,
'lifetime' => env('SESSION_LIFETIME', 120),
```

### 3. Headers CORS (Ya configurado en config/cors.php)

```php
'allowed_origins' => [
    'https://docqr.geofal.com.pe',
    'https://www.docqr.geofal.com.pe'
],
'supports_credentials' => true,
```

### 4. Verificar que HTTPS esté Activo

**Las cookies seguras SOLO funcionan con HTTPS**

```bash
# Verificar certificado SSL
curl -I https://docqr-api.geofal.com.pe

# Debe mostrar: HTTP/2 200 (no HTTP/1.1 sin SSL)
```

---

## 📦 Instalación Paso a Paso en Servidor

### 1. Instalar Extensiones PHP (cPanel)

```
cPanel → Select PHP Version → Extensions
Marcar todas las mencionadas arriba
```

### 2. Crear Base de Datos

```sql
CREATE DATABASE geofal_docqr CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'geofal_docqr_user'@'localhost' IDENTIFIED BY 'password_seguro';
GRANT ALL PRIVILEGES ON geofal_docqr.* TO 'geofal_docqr_user'@'localhost';
FLUSH PRIVILEGES;
```

### 3. Subir y Extraer Archivos

```bash
# Backend
cd /home/USUARIO/public_html
unzip BACKEND-GEOFAL-PRODUCCION.zip -d docqr-api/

# Frontend
cd /home/USUARIO/public_html
unzip FRONTEND-GEOFAL-PRODUCCION.zip -d docqr/
```

### 4. Configurar .env

```bash
cd /home/USUARIO/public_html/docqr-api
cp .env.production .env
nano .env

# Editar:
DB_DATABASE=geofal_docqr
DB_USERNAME=geofal_docqr_user
DB_PASSWORD=tu_password
```

### 5. Dar Permisos

```bash
chmod +x php81
chmod +x setup-cpanel.sh
chmod -R 775 storage bootstrap/cache
```

### 6. Ejecutar Instalación

```bash
./setup-cpanel.sh
```

### 7. Instalar SSL (cPanel)

```
cPanel → SSL/TLS → Manage SSL Sites
Instalar para:
- docqr.geofal.com.pe
- docqr-api.geofal.com.pe
```

### 8. Verificar

```
https://docqr-api.geofal.com.pe/verificar_produccion.php
```

---

## 🔍 Verificación Final

### Checklist de Producción:

- [ ] PHP 8.1+ instalado
- [ ] Todas las extensiones PHP instaladas
- [ ] MySQL/MariaDB funcionando
- [ ] Base de datos creada
- [ ] Composer disponible
- [ ] Archivos subidos y extraídos
- [ ] Permisos configurados (775 en storage/)
- [ ] .env configurado correctamente
- [ ] SSL/HTTPS activo
- [ ] Cookies configuradas para HTTPS
- [ ] Script de instalación ejecutado
- [ ] Verificación al 100%

---

## 🚨 Solución de Problemas Comunes

### Error: "Extension not found"

```bash
# cPanel: Select PHP Version → Extensions
# Activar las extensiones faltantes
```

### Error: "Permission denied" en storage/

```bash
chmod -R 775 storage bootstrap/cache
chown -R USUARIO:USUARIO storage bootstrap/cache
```

### Error de Cookies/Sesiones

```bash
# Verificar HTTPS
curl -I https://docqr-api.geofal.com.pe | grep "HTTP"

# Debe mostrar: HTTP/2 200 o HTTPS

# Verificar .env
SESSION_SECURE_COOKIE=true  # Si tienes HTTPS
SESSION_SECURE_COOKIE=false # Si NO tienes HTTPS (solo desarrollo)
```

### Error de Base de Datos

```bash
# Verificar conexión
mysql -h 127.0.0.1 -u geofal_docqr_user -p geofal_docqr

# Si funciona, revisar .env:
DB_HOST=127.0.0.1  # Usar IP, no 'localhost'
```

---

## 📞 Contacto

Si algún requisito no se puede cumplir, contactar a soporte del hosting para:
- Instalar extensiones PHP faltantes
- Aumentar límites PHP
- Configurar permisos especiales
- Habilitar funciones deshabilitadas

---

**✅ Con todos estos requisitos cumplidos, el sistema funcionará al 100%**

