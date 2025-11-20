# ✅ CHECKLIST DE DESPLIEGUE A PRODUCCIÓN

## 📋 PRE-REQUISITOS DEL SERVIDOR

### 1. Versión de PHP
- [ ] PHP **8.1 o superior** instalado
- [ ] Verificar: `php -v` debe mostrar 8.1.x o 8.2.x
- [ ] Si es menor: Actualizar desde cPanel → MultiPHP Manager

### 2. Extensiones PHP Requeridas
- [ ] mbstring
- [ ] xml
- [ ] curl
- [ ] zip
- [ ] gd
- [ ] mysql/mysqli
- [ ] Verificar en cPanel → Select PHP Version

### 3. Base de Datos
- [ ] MySQL 5.7+ o MariaDB 10.3+
- [ ] Base de datos creada (ej: `grersced_docqr`)
- [ ] Usuario MySQL con todos los privilegios

---

## 📦 ARCHIVOS A SUBIR

### Backend (API)
- [ ] `docqr-api-PRODUCCION.zip` (~15 MB)
- [ ] Extraer en: `/home/usuario/docqr-api.geofal.com.pe/`

### Frontend
- [ ] `docqr-frontend-PRODUCTION-FINAL.zip` (~7 MB)
- [ ] Extraer en: `/home/usuario/docqr.geofal.com.pe/`

### Base de Datos
- [ ] Importar `docqr-database.sql` en phpMyAdmin

---

## ⚙️ CONFIGURACIÓN DEL BACKEND

### 1. Crear archivo .env
```bash
# En el servidor, dentro de la carpeta de la API:
nano .env

# Copiar el contenido de ENV_PRODUCTION_TEMPLATE.txt
# Completar TODAS las variables marcadas con ⚠️
```

### Variables críticas a configurar:
- [ ] `APP_KEY=` (se genera con artisan)
- [ ] `APP_URL=https://docqr-api.geofal.com.pe`
- [ ] `FRONTEND_URL=https://docqr.geofal.com.pe`
- [ ] `DB_DATABASE=tu_base_datos`
- [ ] `DB_USERNAME=tu_usuario`
- [ ] `DB_PASSWORD=tu_contraseña`

### 2. Comandos de Inicialización
```bash
# Generar clave de aplicación
php artisan key:generate

# Ejecutar migraciones
php artisan migrate --force

# Crear enlace simbólico de storage
php artisan storage:link

# Optimizar aplicación
php artisan optimize

# Asignar permisos
chmod -R 775 storage bootstrap/cache
```

---

## 🌐 CONFIGURACIÓN DEL FRONTEND

### Verificar archivo environment.prod.ts
El frontend ya está compilado con la URL de producción:
```typescript
apiUrl: 'https://docqr-api.geofal.com.pe/api'
```

**No necesitas cambiar nada** si subiste el ZIP correcto.

---

## 🔒 SEGURIDAD

### 1. Permisos de Archivos
```bash
# Archivos: 644
find . -type f -exec chmod 644 {} \;

# Carpetas: 755
find . -type d -exec chmod 755 {} \;

# Storage y cache: 775
chmod -R 775 storage bootstrap/cache
```

### 2. Archivo .htaccess (Backend)
Verificar que `public/.htaccess` tenga:
```apache
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-d
RewriteCond %{REQUEST_FILENAME} !-f
RewriteRule ^ index.php [L]
```

### 3. Variables de Entorno
- [ ] `APP_ENV=production` (NO `local` o `development`)
- [ ] `APP_DEBUG=false` (NO `true`)
- [ ] `LOG_LEVEL=error` (NO `debug`)

---

## 🧪 PRUEBAS POST-DESPLIEGUE

### 1. Health Check de la API
```bash
# Debe responder: {"status":"ok"}
curl https://docqr-api.geofal.com.pe/up
```

### 2. Test de CORS
```bash
# Desde el navegador en https://docqr.geofal.com.pe
# Abrir consola (F12) y ejecutar:
fetch('https://docqr-api.geofal.com.pe/up')
  .then(r => r.json())
  .then(console.log)

# NO debe haber errores de CORS
```

### 3. Test de Login
- [ ] Ir a `https://docqr.geofal.com.pe`
- [ ] Intentar hacer login
- [ ] Verificar que NO haya errores de CORS
- [ ] Verificar que redirija al dashboard

### 4. Test de Subida de PDF
- [ ] Subir un PDF
- [ ] Generar código QR
- [ ] Posicionar QR en el editor
- [ ] Guardar y verificar que se descarga

---

## 🆘 SOLUCIÓN DE PROBLEMAS

### Error: "500 Internal Server Error"
**Causa:** Permisos incorrectos o configuración .env
**Solución:**
```bash
chmod -R 775 storage bootstrap/cache
php artisan optimize:clear
# Revisar logs: storage/logs/laravel.log
```

### Error: "CORS Policy Blocked"
**Causa:** APP_ENV no está en 'production' o FRONTEND_URL incorrecta
**Solución:**
```bash
# En .env:
APP_ENV=production
FRONTEND_URL=https://docqr.geofal.com.pe

php artisan optimize:clear
```

### Error: "Class not found"
**Causa:** vendor/ no se subió o está incompleto
**Solución:**
```bash
# Verificar que existe vendor/:
ls -la vendor/

# Debe tener carpetas: laravel, symfony, endroid, setasign, tecnickcom
```

### Error: "Could not find driver"
**Causa:** Extensión MySQL no instalada
**Solución:**
- cPanel → Select PHP Version
- Activar: mysqli y pdo_mysql

### Error: "Token inválido" o "401 Unauthorized"
**Causa:** Problema con Sanctum o dominio incorrecto
**Solución:**
```bash
# En .env verificar:
SANCTUM_STATEFUL_DOMAINS=docqr.geofal.com.pe
SESSION_DOMAIN=.geofal.com.pe

php artisan optimize:clear
```

---

## 📊 VERIFICACIÓN FINAL

- [ ] API responde en `https://docqr-api.geofal.com.pe/up`
- [ ] Frontend carga en `https://docqr.geofal.com.pe`
- [ ] Login funciona sin errores
- [ ] Se pueden subir PDFs
- [ ] Se pueden generar QRs
- [ ] Se pueden posicionar y guardar QRs
- [ ] Los PDFs se descargan correctamente
- [ ] No hay errores de CORS en la consola del navegador
- [ ] No hay errores 500 en ninguna funcionalidad

---

## 🎉 ¡SISTEMA EN PRODUCCIÓN!

Si completaste todos los pasos, tu sistema DocQR está **100% operativo** en producción.

**Logs importantes:**
- Backend: `storage/logs/laravel.log`
- Errores PHP: Revisar en cPanel → Error Log

**Monitoreo:**
- Revisar logs diariamente los primeros 7 días
- Hacer backups semanales de la base de datos
- Mantener PHP actualizado

---

**Fecha de última actualización:** 2025-01-20
**Versión del sistema:** 1.0.0

