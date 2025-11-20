# 🚀 Instrucciones de Despliegue Rápido - DocQR Geofal

## 📦 Archivos Incluidos

- `FRONTEND-GEOFAL-PRODUCCION.zip` → Frontend compilado (Angular)
- `BACKEND-GEOFAL-PRODUCCION.zip` → Backend optimizado (Laravel)

## 🌐 Subdominios Necesarios

1. **Frontend:** `docqr.geofal.com.pe`
2. **Backend:** `docqr-api.geofal.com.pe`

---

## ⚡ Despliegue en 10 Pasos

### 1️⃣ Crear Subdominios en cPanel

- Ir a **cPanel → Dominios → Subdominios**
- Crear `docqr` → Raíz: `public_html/docqr`
- Crear `docqr-api` → Raíz: `public_html/docqr-api/public`

### 2️⃣ Subir y Extraer Archivos

**Frontend:**
```
Subir: FRONTEND-GEOFAL-PRODUCCION.zip a public_html/docqr/
Extraer en: public_html/docqr/
```

**Backend:**
```
Subir: BACKEND-GEOFAL-PRODUCCION.zip a public_html/docqr-api/
Extraer en: public_html/docqr-api/
```

### 3️⃣ Configurar PHP (IMPORTANTE)

**cPanel → MultiPHP INI Editor:**
- Seleccionar dominio: `docqr-api.geofal.com.pe`
- Modificar:
  ```
  upload_max_filesize = 500M
  post_max_size = 510M
  memory_limit = 1024M
  max_execution_time = 600
  max_input_time = 600
  ```

**O copiar archivo .user.ini:**
```bash
cp /home/usuario/public_html/docqr-api/.user.ini.example /home/usuario/public_html/docqr-api/.user.ini
```

### 4️⃣ Crear Base de Datos

**cPanel → MySQL® Databases:**
1. Crear BD: `geofal_docqr`
2. Crear usuario: `geofal_docqr_user`
3. Asignar privilegios: **TODOS**

### 5️⃣ Configurar .env

```bash
cd public_html/docqr-api
cp .env.production .env
nano .env
```

**Modificar estas líneas:**
```env
APP_KEY=                           # Dejar vacío, se generará automáticamente
DB_DATABASE=geofal_docqr
DB_USERNAME=geofal_docqr_user
DB_PASSWORD=TU_PASSWORD_AQUI
```

### 6️⃣ Ejecutar Script de Instalación

**Opción A: Script Automatizado (RECOMENDADO)**
```bash
cd public_html/docqr-api
chmod +x setup-cpanel.sh
./setup-cpanel.sh
```

**Opción B: Manual**
```bash
cd public_html/docqr-api

# Dar permisos a script PHP
chmod +x php81

# Instalar dependencias
./php81 $(which composer) install --no-dev --optimize-autoloader

# Generar APP_KEY
./php81 artisan key:generate

# Migrar base de datos
./php81 artisan migrate --force

# Optimizar
./php81 artisan config:cache
./php81 artisan route:cache
./php81 artisan view:cache

# Permisos
chmod -R 775 storage bootstrap/cache

# Crear admin
./php81 database/scripts/crear_usuario_admin.php
```

### 7️⃣ Instalar Certificados SSL

**cPanel → SSL/TLS:**
1. Instalar SSL para `docqr.geofal.com.pe`
2. Instalar SSL para `docqr-api.geofal.com.pe`
3. Forzar HTTPS (ya configurado en .htaccess)

### 8️⃣ Verificar Instalación

Visitar: `https://docqr-api.geofal.com.pe/verificar_produccion.php`

Debe mostrar **100% de verificaciones pasadas**

### 9️⃣ Probar Sistema

1. Ir a: `https://docqr.geofal.com.pe`
2. Login: `admin` / `admin123`
3. Subir un PDF de prueba
4. Verificar que funciona correctamente

### 🔟 Configurar Cron Job (Opcional)

**cPanel → Cron Jobs:**
```
Frecuencia: 0 2 * * *  (Diario 2:00 AM)
Comando: /opt/cpanel/ea-php81/root/usr/bin/php /home/USUARIO/public_html/docqr-api/artisan documents:force-delete-old 30
```

---

## 🔧 Comandos Útiles

### Verificar versión de PHP
```bash
./php81 -v
```

### Ver logs de errores
```bash
tail -f storage/logs/laravel.log
```

### Limpiar cache
```bash
./php81 artisan cache:clear
./php81 artisan config:clear
```

### Regenerar optimizaciones
```bash
./php81 artisan config:cache
./php81 artisan route:cache
```

---

## 🆘 Solución de Problemas Comunes

### Error 500
```bash
chmod -R 775 storage bootstrap/cache
./php81 artisan config:clear
```

### Error de Base de Datos
```bash
# Verificar conexión
mysql -h 127.0.0.1 -u geofal_docqr_user -p geofal_docqr

# Si falla, editar .env:
DB_HOST=127.0.0.1  # No usar 'localhost'
```

### Error de Permisos
```bash
cd public_html/docqr-api
chmod -R 775 storage bootstrap/cache
chown -R USUARIO:USUARIO storage bootstrap/cache
```

### Upload No Funciona
```bash
# Verificar PHP:
./php81 -i | grep upload_max_filesize
# Debe mostrar: 500M

# Si no, editar .user.ini y reiniciar Apache
```

### CORS Error
```bash
# Verificar .env:
CORS_ALLOWED_ORIGINS=https://docqr.geofal.com.pe

# Limpiar cache:
./php81 artisan config:cache
```

---

## 📞 Soporte

**Verificación Completa:**
```
https://docqr-api.geofal.com.pe/verificar_produccion.php
```

**Documentación Completa:**
- `DESPLIEGUE_GEOFAL.md` - Guía detallada
- `CHECKLIST_GEOFAL.md` - Checklist completo
- `README.md` - Información general

**Logs:**
```bash
tail -f storage/logs/laravel.log
```

---

## ✅ Checklist Final

- [ ] Subdominios creados
- [ ] Archivos subidos y extraídos
- [ ] PHP configurado (500M)
- [ ] Base de datos creada
- [ ] .env configurado
- [ ] Script de instalación ejecutado
- [ ] SSL instalado
- [ ] Verificación 100% pasada
- [ ] Login funciona
- [ ] Upload PDF funciona

---

**🎯 Tiempo estimado: 15-30 minutos**

**✅ ¡Sistema listo para producción!**

