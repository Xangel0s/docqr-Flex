# 🚀 Instalación Completa en cPanel - DocQR Geofal

## Guía Paso a Paso para Despliegue en Producción

---

## 📋 Antes de Empezar

**Archivos necesarios:**
- `FRONTEND-GEOFAL-PRODUCCION.zip`
- `BACKEND-GEOFAL-PRODUCCION.zip` (o crear manualmente)
- `database_structure.sql`

**Acceso necesario:**
- Panel de cPanel
- Acceso SSH (opcional, pero recomendado)
- Credenciales de base de datos

---

## 🎯 PARTE 1: Preparación del Servidor

### Paso 1: Verificar PHP y Extensiones

**cPanel → Select PHP Version:**

1. Seleccionar **PHP 8.1** o superior
2. Ir a **Extensions** y activar:
   ```
   ☑ bcmath
   ☑ ctype
   ☑ curl
   ☑ dom
   ☑ fileinfo
   ☑ gd
   ☑ json
   ☑ mbstring
   ☑ openssl
   ☑ pdo
   ☑ pdo_mysql
   ☑ session
   ☑ xml
   ☑ zip
   ```

### Paso 2: Configurar Límites de PHP

**cPanel → MultiPHP INI Editor:**

```ini
upload_max_filesize = 500M
post_max_size = 510M
memory_limit = 1024M
max_execution_time = 600
max_input_time = 600
```

**Guardar cambios**

---

## 🗄️ PARTE 2: Base de Datos

### Paso 3: Crear Base de Datos

**cPanel → MySQL® Databases:**

1. **Crear nueva base de datos:**
   - Nombre: `geofal_docqr`
   - Charset: `utf8mb4`

2. **Crear usuario:**
   - Nombre: `geofal_docqr_user`
   - Contraseña: (generar segura)
   - **¡GUARDAR estas credenciales!**

3. **Asignar privilegios:**
   - Seleccionar usuario y base de datos
   - Marcar: **ALL PRIVILEGES**
   - Agregar

### Paso 4: Importar Estructura

**Opción A: phpMyAdmin**
```
1. Ir a phpMyAdmin
2. Seleccionar base de datos: geofal_docqr
3. Click en "Importar"
4. Seleccionar archivo: database_structure.sql
5. Click "Continuar"
```

**Opción B: SSH**
```bash
mysql -u geofal_docqr_user -p geofal_docqr < database_structure.sql
```

---

## 🌐 PARTE 3: Subdominios

### Paso 5: Crear Subdominios

**cPanel → Dominios → Subdominios:**

**Subdominio 1: Frontend**
```
Subdominio: docqr
Dominio: geofal.com.pe
Raíz del documento: public_html/docqr
```

**Subdominio 2: Backend**
```
Subdominio: docqr-api
Dominio: geofal.com.pe
Raíz del documento: public_html/docqr-api/public
```

**Crear ambos subdominios**

---

## 📦 PARTE 4: Subir Archivos

### Paso 6: Subir ZIPs

**cPanel → Administrador de archivos:**

1. Ir a `public_html/`
2. Crear carpeta `docqr/`
3. Crear carpeta `docqr-api/`
4. Subir `FRONTEND-GEOFAL-PRODUCCION.zip` a `docqr/`
5. Subir `BACKEND-GEOFAL-PRODUCCION.zip` a `docqr-api/`

### Paso 7: Extraer Archivos

**En Administrador de archivos:**

1. Click derecho en `FRONTEND-GEOFAL-PRODUCCION.zip` → **Extraer**
   - Extraer a: `/public_html/docqr/`
   
2. Click derecho en `BACKEND-GEOFAL-PRODUCCION.zip` → **Extraer**
   - Extraer a: `/public_html/docqr-api/`

3. **Eliminar** los archivos ZIP

---

## ⚙️ PARTE 5: Configurar Backend

### Paso 8: Configurar .env

**SSH o Editor de archivos:**

```bash
cd /home/USUARIO/public_html/docqr-api

# Copiar archivo de ejemplo
cp .env.production .env

# Editar archivo
nano .env
```

**Configurar estas líneas IMPORTANTES:**

```env
APP_ENV=production
APP_DEBUG=false
APP_KEY=                                    # Se generará automáticamente
APP_URL=https://docqr-api.geofal.com.pe

FRONTEND_URL=https://docqr.geofal.com.pe
CORS_ALLOWED_ORIGINS=https://docqr.geofal.com.pe,https://www.docqr.geofal.com.pe

# Base de datos (USAR TUS CREDENCIALES)
DB_HOST=127.0.0.1
DB_DATABASE=geofal_docqr
DB_USERNAME=geofal_docqr_user
DB_PASSWORD=TU_PASSWORD_AQUI

# Sesiones (HTTPS)
SESSION_SECURE_COOKIE=true
SESSION_SAME_SITE=None
SESSION_LIFETIME=480
```

**Guardar** (Ctrl+O, Enter, Ctrl+X en nano)

### Paso 9: Copiar Configuración PHP

```bash
cp .user.ini.example .user.ini
```

### Paso 10: Dar Permisos a Scripts

```bash
chmod +x php81
chmod +x setup-cpanel.sh
chmod +x artisan
```

### Paso 11: Dar Permisos a Carpetas

```bash
chmod -R 775 storage
chmod -R 775 bootstrap/cache

# Verificar
ls -la storage
ls -la bootstrap/cache
```

### Paso 12: Ejecutar Instalación Automatizada

```bash
./setup-cpanel.sh
```

**Este script hará:**
1. ✅ Instalar dependencias de Composer
2. ✅ Generar APP_KEY
3. ✅ Ejecutar migraciones de base de datos
4. ✅ Optimizar Laravel
5. ✅ Crear usuario administrador
6. ✅ Configurar permisos

**Si hay errores, ejecutar manualmente:**

```bash
# Instalar dependencias
./php81 /usr/local/bin/composer install --no-dev --optimize-autoloader

# Generar APP_KEY
./php81 artisan key:generate

# Migrar base de datos
./php81 artisan migrate --force

# Optimizar
./php81 artisan config:cache
./php81 artisan route:cache
./php81 artisan view:cache
./php81 artisan optimize

# Crear admin
./php81 database/scripts/crear_usuario_admin.php
```

---

## 🔐 PARTE 6: Configurar SSL/HTTPS

### Paso 13: Instalar Certificados SSL

**cPanel → SSL/TLS → Manage SSL Sites:**

1. Buscar: `docqr.geofal.com.pe`
   - Click **Install SSL**
   - Seleccionar certificado (Let's Encrypt)
   - Instalar

2. Buscar: `docqr-api.geofal.com.pe`
   - Click **Install SSL**
   - Seleccionar certificado
   - Instalar

3. Buscar: `www.docqr.geofal.com.pe` (opcional)
   - Instalar también

**Verificar:**
```
https://docqr.geofal.com.pe (debe mostrar candado verde)
https://docqr-api.geofal.com.pe (debe mostrar candado verde)
```

---

## 🔄 PARTE 7: Configurar Tareas Programadas

### Paso 14: Crear Cron Job

**cPanel → Cron Jobs:**

**Configuración:**
```
Minuto: 0
Hora: 2
Día: *
Mes: *
Día de la semana: *

Comando:
/opt/cpanel/ea-php81/root/usr/bin/php /home/USUARIO/public_html/docqr-api/artisan documents:force-delete-old 30
```

**Reemplazar `USUARIO` con tu usuario de cPanel**

**Agregar**

---

## ✅ PARTE 8: Verificación

### Paso 15: Verificar Instalación

**Abrir navegador:**
```
https://docqr-api.geofal.com.pe/verificar_produccion.php
```

**Debe mostrar:**
- ✅ PHP configurado correctamente
- ✅ Variables de entorno OK
- ✅ Base de datos conectada
- ✅ Permisos correctos
- ✅ Porcentaje: 100%

### Paso 16: Probar Sistema

1. **Ir a:** `https://docqr.geofal.com.pe`
2. **Login:**
   - Usuario: `admin`
   - Contraseña: `admin123`
3. **Cambiar contraseña inmediatamente**
4. **Subir un PDF de prueba**
5. **Verificar que todo funciona**

---

## 🧪 PARTE 9: Pruebas Funcionales

### Checklist de Pruebas:

- [ ] Login funciona
- [ ] Dashboard carga correctamente
- [ ] Subir PDF pequeño (<5MB) funciona
- [ ] Subir PDF grande (>100MB) funciona
- [ ] Adjuntar PDF funciona
- [ ] Editor de QR funciona
- [ ] Guardar posición de QR funciona
- [ ] Recargar página (F5) - cambios persisten
- [ ] Eliminar documento funciona
- [ ] Descargar QR funciona
- [ ] Ver PDF final funciona
- [ ] Estadísticas actualizan en tiempo real
- [ ] No hay errores en consola (F12)

---

## 🎯 PARTE 10: Post-Instalación

### Paso 17: Seguridad

```bash
# Verificar permisos de .env
chmod 600 .env

# Verificar que archivos sensibles no sean accesibles vía web
# Probar en navegador (debe dar 403):
https://docqr-api.geofal.com.pe/.env
https://docqr-api.geofal.com.pe/composer.json
```

### Paso 18: Configurar Backups

**cPanel → Backups:**

1. Configurar backup automático diario
2. Incluir:
   - Base de datos: `geofal_docqr`
   - Directorio: `public_html/docqr-api/storage/`

### Paso 19: Monitoreo

**Crear script de monitoreo:**

```bash
cd /home/USUARIO/public_html/docqr-api
cat > monitor.sh << 'EOF'
#!/bin/bash
# Verificar que el sistema esté funcionando
curl -sf https://docqr-api.geofal.com.pe/verificar_produccion.php > /dev/null
if [ $? -eq 0 ]; then
    echo "Sistema OK"
else
    echo "Sistema CAÍDO - Verificar logs"
    tail -20 storage/logs/laravel.log
fi
EOF

chmod +x monitor.sh
```

---

## 📞 PARTE 11: Troubleshooting

### Problema: Error 500

```bash
# Ver logs
tail -f storage/logs/laravel.log

# Verificar permisos
chmod -R 775 storage bootstrap/cache

# Limpiar cache
./php81 artisan config:clear
./php81 artisan cache:clear
```

### Problema: Base de datos no conecta

```bash
# Probar conexión manualmente
mysql -h 127.0.0.1 -u geofal_docqr_user -p geofal_docqr

# Si funciona, el problema está en .env
# Verificar:
DB_HOST=127.0.0.1  # NO usar 'localhost'
DB_DATABASE=geofal_docqr
DB_USERNAME=geofal_docqr_user
DB_PASSWORD=tu_password_correcta
```

### Problema: Upload no funciona

```bash
# Verificar PHP
./php81 -i | grep upload_max_filesize
# Debe mostrar: 500M

# Verificar .user.ini
cat .user.ini

# Reiniciar Apache
# (En cPanel: Restart Services → Apache)
```

### Problema: CORS Error

```bash
# Verificar .env
grep CORS .env

# Debe tener:
CORS_ALLOWED_ORIGINS=https://docqr.geofal.com.pe

# Limpiar cache
./php81 artisan config:cache
```

---

## 📊 Resumen de Rutas y Archivos

### URLs Finales:
```
Frontend: https://docqr.geofal.com.pe
Backend:  https://docqr-api.geofal.com.pe
Verificación: https://docqr-api.geofal.com.pe/verificar_produccion.php
Admin: admin / admin123
```

### Estructura de Directorios:
```
/home/USUARIO/public_html/
├── docqr/                     ← Frontend
│   ├── index.html
│   ├── *.js, *.css
│   ├── assets/
│   └── .htaccess
│
└── docqr-api/                 ← Backend
    ├── app/
    ├── bootstrap/cache/       (775)
    ├── config/
    ├── database/
    ├── public/                ← Raíz web
    │   ├── index.php
    │   ├── .htaccess
    │   └── verificar_produccion.php
    ├── storage/               (775)
    │   ├── app/
    │   │   ├── pdfs/
    │   │   ├── qrs/
    │   │   └── final_pdfs/
    │   └── logs/
    ├── .env                   (600)
    ├── .user.ini
    ├── php81                  (755)
    └── setup-cpanel.sh        (755)
```

---

## ✅ Checklist Final

- [ ] PHP 8.1+ configurado
- [ ] Extensiones PHP instaladas
- [ ] Límites PHP configurados (500M)
- [ ] Base de datos creada e importada
- [ ] Subdominios creados
- [ ] Archivos subidos y extraídos
- [ ] .env configurado
- [ ] Permisos correctos
- [ ] Script de instalación ejecutado
- [ ] SSL instalado en ambos subdominios
- [ ] Cron job configurado
- [ ] Verificación al 100%
- [ ] Pruebas funcionales OK
- [ ] Backups configurados
- [ ] Contraseña de admin cambiada

---

**🎉 ¡Sistema listo para producción al 100%!**

**Tiempo estimado total: 45-90 minutos**

