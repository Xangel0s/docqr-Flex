# ✅ Checklist Pre-Producción - DocQR Geofal

## 📋 Lista de Verificación Completa

Marcar cada ítem antes de lanzar a producción.

---

## 🌐 1. Configuración de Dominios y DNS

### Subdominios
- [ ] DNS configurado para `docqr.geofal.com.pe` → IP del servidor
- [ ] DNS configurado para `docqr-api.geofal.com.pe` → IP del servidor
- [ ] DNS configurado para `www.docqr.geofal.com.pe` (opcional)
- [ ] Verificar propagación DNS (usar `nslookup` o `dig`)
- [ ] Subdominios creados en cPanel
- [ ] Raíz de documentos correcta para cada subdominio

**Comandos de verificación:**
```bash
nslookup docqr.geofal.com.pe
nslookup docqr-api.geofal.com.pe
```

---

## 🔒 2. Certificados SSL/HTTPS

### SSL
- [ ] Certificado SSL instalado para `docqr.geofal.com.pe`
- [ ] Certificado SSL instalado para `docqr-api.geofal.com.pe`
- [ ] Certificado SSL instalado para `www.docqr.geofal.com.pe` (si aplica)
- [ ] HTTPS funcionando en ambos subdominios
- [ ] Redirección automática HTTP → HTTPS configurada
- [ ] Verificar certificado válido (candado verde en navegador)

**URLs a verificar:**
- https://docqr.geofal.com.pe
- https://docqr-api.geofal.com.pe

---

## ⚙️ 3. Configuración de PHP

### Límites de Archivos
- [ ] `upload_max_filesize = 500M`
- [ ] `post_max_size = 510M`
- [ ] `memory_limit = 1024M`
- [ ] `max_execution_time = 600`
- [ ] `max_input_time = 600`
- [ ] `max_input_vars = 3000`

### Extensiones PHP
- [ ] `mbstring` instalada
- [ ] `openssl` instalada
- [ ] `pdo` instalada
- [ ] `pdo_mysql` instalada
- [ ] `curl` instalada
- [ ] `zip` instalada
- [ ] `gd` instalada
- [ ] `xml` instalada
- [ ] `json` instalada
- [ ] `fileinfo` instalada

**Verificar:**
```
https://docqr-api.geofal.com.pe/verificar_produccion.php
```

---

## 🗄️ 4. Base de Datos

### Creación y Configuración
- [ ] Base de datos `geofal_docqr` creada
- [ ] Usuario `geofal_docqr_user` creado
- [ ] Privilegios asignados correctamente
- [ ] Charset: `utf8mb4`
- [ ] Collation: `utf8mb4_unicode_ci`
- [ ] Conexión probada desde PHP

### Migraciones
- [ ] Todas las migraciones ejecutadas (`php artisan migrate --force`)
- [ ] Tabla `qr_files` existe
- [ ] Tabla `users` existe
- [ ] Índices creados correctamente
- [ ] Usuario administrador creado

**Verificar conexión:**
```bash
mysql -h 127.0.0.1 -u geofal_docqr_user -p geofal_docqr
```

---

## 🔧 5. Backend (Laravel API)

### Archivos y Configuración
- [ ] Todos los archivos subidos al servidor
- [ ] Ubicación correcta: `public_html/docqr-api/`
- [ ] Archivo `.env` existe (copiado de `.env.production`)
- [ ] `APP_KEY` generado
- [ ] `APP_ENV=production`
- [ ] `APP_DEBUG=false`
- [ ] `APP_URL=https://docqr-api.geofal.com.pe`
- [ ] `FRONTEND_URL=https://docqr.geofal.com.pe`
- [ ] `CORS_ALLOWED_ORIGINS` configurado
- [ ] `SESSION_SECURE_COOKIE=true`
- [ ] Credenciales de BD correctas en `.env`

### Dependencias
- [ ] `composer install --no-dev --optimize-autoloader` ejecutado
- [ ] Carpeta `vendor/` existe y está completa

### Optimización
- [ ] `php artisan config:cache` ejecutado
- [ ] `php artisan route:cache` ejecutado
- [ ] `php artisan view:cache` ejecutado
- [ ] `php artisan optimize` ejecutado

### Permisos
- [ ] `storage/` tiene permisos 775
- [ ] `bootstrap/cache/` tiene permisos 775
- [ ] Owner correcto (usuario de cPanel o www-data)
- [ ] Subdirectorios de `storage/app/` creados:
  - [ ] `storage/app/pdfs/`
  - [ ] `storage/app/qrs/`
  - [ ] `storage/app/final_pdfs/`

### Archivos de Configuración
- [ ] `.htaccess` en `public/` existe y está correcto
- [ ] `.user.ini` creado con límites de PHP (si aplica)

**Comandos de verificación:**
```bash
cd public_html/docqr-api
ls -la storage
ls -la bootstrap/cache
php artisan --version
```

---

## 🎨 6. Frontend (Angular)

### Build y Despliegue
- [ ] `npm install` ejecutado localmente
- [ ] `npm run build --prod` ejecutado
- [ ] Archivos de `dist/docqr-frontend/` subidos a servidor
- [ ] Ubicación correcta: `public_html/docqr/`
- [ ] `index.html` existe en la raíz
- [ ] Archivos `.js` y `.css` presentes
- [ ] Carpeta `assets/` presente

### Configuración
- [ ] `environment.prod.ts` con URL correcta
- [ ] `apiUrl: 'https://docqr-api.geofal.com.pe/api'`
- [ ] `baseUrl: 'https://docqr.geofal.com.pe'`

### Archivos de Servidor
- [ ] `.htaccess` en raíz con reescritura para Angular
- [ ] Redirección HTTP → HTTPS configurada

**Verificar acceso:**
- https://docqr.geofal.com.pe

---

## 🔄 7. Cron Jobs y Tareas Programadas

### Limpieza Automática
- [ ] Cron job configurado para eliminar documentos antiguos
- [ ] Frecuencia: Diario a las 2:00 AM
- [ ] Comando correcto con ruta absoluta
- [ ] Probado manualmente una vez

**Comando:**
```bash
0 2 * * * /usr/bin/php /home/[USUARIO]/public_html/docqr-api/artisan documents:force-delete-old 30
```

---

## 🧪 8. Pruebas Funcionales

### Acceso y Autenticación
- [ ] Frontend carga correctamente
- [ ] No hay errores en consola del navegador (F12)
- [ ] Login funciona con `admin` / `admin123`
- [ ] Redirección correcta después del login
- [ ] Logout funciona correctamente

### Funcionalidad de Subida
- [ ] Subir PDF pequeño (<5MB) funciona
- [ ] Subir PDF mediano (50-100MB) funciona
- [ ] Subir PDF grande (200-500MB) funciona
- [ ] Barra de progreso se muestra correctamente
- [ ] Mensaje de éxito aparece
- [ ] Documento aparece en la lista inmediatamente

### Adjuntar PDF
- [ ] Seleccionar documento existente
- [ ] Adjuntar PDF funciona
- [ ] PDF se muestra en la vista previa
- [ ] Botón "Guardar y Finalizar" funciona
- [ ] Cambios persisten después de recargar (F5)

### Editor de QR
- [ ] Editor carga correctamente
- [ ] PDF se visualiza en el canvas
- [ ] QR se puede arrastrar
- [ ] Posición del QR se guarda correctamente
- [ ] Cambios persisten después de recargar
- [ ] PDF final se genera con QR en posición correcta

### Gestión de Documentos
- [ ] Lista de documentos se carga
- [ ] Búsqueda funciona
- [ ] Filtros funcionan
- [ ] Paginación funciona
- [ ] Eliminar documento funciona
- [ ] Documento desaparece inmediatamente de la lista
- [ ] No hay "documentos fantasma" después de eliminar

### Descargas
- [ ] Descargar código QR (imagen PNG) funciona
- [ ] Copiar QR al portapapeles funciona
- [ ] Descargar PDF final funciona
- [ ] Ver PDF en navegador funciona

### Estadísticas
- [ ] Dashboard carga correctamente
- [ ] Estadísticas se actualizan en tiempo real
- [ ] Gráficos se muestran correctamente
- [ ] No hay errores en consola

---

## 🔍 9. Verificación de Sistema

### Script de Verificación
- [ ] Acceder a `https://docqr-api.geofal.com.pe/verificar_produccion.php`
- [ ] Todas las verificaciones pasan (✅)
- [ ] Porcentaje de éxito: 100%
- [ ] No hay errores rojos (❌)

### Logs
- [ ] No hay errores en `storage/logs/laravel.log`
- [ ] Logs de Apache/Nginx sin errores críticos
- [ ] No hay errores 500 en navegador

### Rendimiento
- [ ] Tiempo de carga del frontend < 3 segundos
- [ ] Tiempo de respuesta API < 1 segundo
- [ ] Subida de PDFs funciona sin timeout
- [ ] No hay problemas de memoria

---

## 🔒 10. Seguridad

### Configuración de Seguridad
- [ ] `APP_DEBUG=false` en producción
- [ ] HTTPS forzado en ambos subdominios
- [ ] CORS configurado correctamente
- [ ] `SESSION_SECURE_COOKIE=true`
- [ ] Archivos `.env` protegidos (no accesibles vía web)
- [ ] Directorio `storage/` no accesible vía web

### Headers de Seguridad
- [ ] `X-Content-Type-Options: nosniff`
- [ ] `X-Frame-Options: SAMEORIGIN`
- [ ] `X-XSS-Protection: 1; mode=block`

**Verificar headers:**
```bash
curl -I https://docqr.geofal.com.pe
curl -I https://docqr-api.geofal.com.pe
```

---

## 📊 11. Monitoreo y Backups

### Backups
- [ ] Backup automático de base de datos configurado
- [ ] Backup de archivos configurado (storage/)
- [ ] Frecuencia de backups: Diario
- [ ] Retención: Mínimo 30 días
- [ ] Backup probado (restauración)

### Monitoreo
- [ ] Monitoreo de uptime configurado (opcional)
- [ ] Alertas de errores configuradas (opcional)
- [ ] Logs rotando correctamente

---

## 📝 12. Documentación

### Archivos de Documentación
- [ ] `README.md` actualizado
- [ ] `DESPLIEGUE_GEOFAL.md` creado
- [ ] `CHECKLIST_GEOFAL.md` (este archivo) creado
- [ ] `.env.production` con valores de ejemplo
- [ ] `build-production.sh` script disponible

### Credenciales Documentadas
- [ ] Usuario admin: `admin` / `admin123`
- [ ] Credenciales de base de datos guardadas de forma segura
- [ ] URLs de acceso documentadas

---

## 🎯 13. Finalización

### Pasos Finales
- [ ] Cambiar contraseña de admin después del primer login
- [ ] Notificar a stakeholders que el sistema está listo
- [ ] Proporcionar URLs de acceso
- [ ] Capacitación de usuarios (si aplica)
- [ ] Documentación entregada

### Verificación Post-Lanzamiento
- [ ] Monitorear logs durante las primeras 24 horas
- [ ] Verificar que no hay errores críticos
- [ ] Confirmar que usuarios pueden acceder
- [ ] Verificar rendimiento bajo carga real

---

## ✅ Resumen

**Estado del Proyecto:**

- [ ] Todos los ítems completados
- [ ] Sistema 100% funcional
- [ ] Documentación completa
- [ ] Backups configurados
- [ ] Equipo informado

**Fecha de lanzamiento:** ___________________

**Responsable:** ___________________

**Firma:** ___________________

---

## 🆘 En Caso de Problemas

Si algún ítem no se puede completar:

1. Revisar logs: `storage/logs/laravel.log`
2. Ejecutar: `verificar_produccion.php`
3. Consultar: `DESPLIEGUE_GEOFAL.md`
4. Verificar permisos: `ls -la storage bootstrap/cache`
5. Ver errores de Apache: Logs en cPanel

---

**📞 URLs de Soporte:**
- Frontend: https://docqr.geofal.com.pe
- Backend API: https://docqr-api.geofal.com.pe
- Verificación: https://docqr-api.geofal.com.pe/verificar_produccion.php

**🎯 ¡Sistema listo para producción cuando todos los ítems estén marcados!**

