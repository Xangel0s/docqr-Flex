# DocQR - Sistema de Gestión de Documentos con Código QR

Sistema completo para gestión de documentos PDF con códigos QR integrados, desarrollado para **Geofal**.

## 🌐 URLs de Producción

- **Frontend:** https://docqr.geofal.com.pe
- **Backend API:** https://docqr-api.geofal.com.pe
- **Verificación:** https://docqr-api.geofal.com.pe/verificar_produccion.php

## 🔐 Acceso por Defecto

**Usuario administrador:**
- Usuario: `admin`
- Contraseña: `admin123`

> ⚠️ **IMPORTANTE:** Cambiar la contraseña después del primer acceso.

## 📋 Características Principales

### ✨ Funcionalidades

- **Generación de QR**: Códigos QR únicos para cada documento
- **Gestión de PDFs**: Subir, procesar y almacenar documentos PDF
- **Editor de Posición**: Posicionar el código QR en cualquier parte del PDF
- **Adjuntar PDFs**: Vincular archivos PDF existentes a códigos QR
- **Visualización**: Ver PDFs con QR integrado
- **Estadísticas**: Dashboard con métricas y análisis
- **Multi-usuario**: Soporte para usuarios simultáneos
- **Archivos grandes**: Soporta PDFs de hasta 500MB
- **Tiempo real**: Sin cache, cambios instantáneos

### 🔧 Tecnologías

**Frontend:**
- Angular 17+
- TypeScript
- TailwindCSS
- PDF.js
- Fabric.js
- QRCode.js

**Backend:**
- PHP 8.1+
- Laravel 10+
- MySQL/MariaDB
- FPDI (PDF manipulation)
- TCPDF (PDF generation)

## 📦 Estructura del Proyecto

```
docqr-Flex/
├── docqr-frontend/          # Aplicación Angular
│   ├── src/
│   │   ├── app/
│   │   ├── environments/    # Configuración de entornos
│   │   └── assets/
│   └── dist/                # Build de producción
│
├── docqr-api/              # API Laravel
│   ├── app/
│   ├── config/
│   ├── database/
│   ├── public/
│   ├── storage/            # Archivos y logs
│   └── .env.production     # Config de producción
│
├── DESPLIEGUE_GEOFAL.md    # Guía de despliegue completa
├── CHECKLIST_GEOFAL.md     # Checklist pre-producción
└── README.md               # Este archivo
```

## 🚀 Instalación y Despliegue

### Desarrollo Local

**Requisitos:**
- Node.js 18+
- PHP 8.1+
- Composer
- MySQL 5.7+ / MariaDB 10.3+

**Backend:**
```bash
cd docqr-api
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan serve
```

**Frontend:**
```bash
cd docqr-frontend
npm install
npm start
```

### Producción en Geofal

**Documentación completa:** Ver [`DESPLIEGUE_GEOFAL.md`](./DESPLIEGUE_GEOFAL.md)

**Pasos rápidos:**

1. **Configurar subdominios DNS:**
   - `docqr.geofal.com.pe` → Frontend
   - `docqr-api.geofal.com.pe` → Backend

2. **Configurar PHP (500MB):**
   ```ini
   upload_max_filesize = 500M
   post_max_size = 510M
   memory_limit = 1024M
   max_execution_time = 600
   ```

3. **Backend:**
   ```bash
   cd docqr-api
   cp .env.production .env
   # Editar .env con credenciales
   composer install --no-dev --optimize-autoloader
   php artisan key:generate
   php artisan migrate --force
   php artisan optimize
   chmod -R 775 storage bootstrap/cache
   ```

4. **Frontend:**
   ```bash
   cd docqr-frontend
   npm install
   npm run build --prod
   # Subir archivos de dist/ al servidor
   ```

5. **Verificar:** https://docqr-api.geofal.com.pe/verificar_produccion.php

## ⚙️ Configuración

### Variables de Entorno (Backend)

Archivo: `docqr-api/.env`

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://docqr-api.geofal.com.pe
FRONTEND_URL=https://docqr.geofal.com.pe
CORS_ALLOWED_ORIGINS=https://docqr.geofal.com.pe,https://www.docqr.geofal.com.pe

DB_DATABASE=geofal_docqr
DB_USERNAME=tu_usuario
DB_PASSWORD=tu_password

SESSION_SECURE_COOKIE=true
SESSION_LIFETIME=480
```

### Configuración de Frontend

Archivo: `docqr-frontend/src/environments/environment.prod.ts`

```typescript
export const environment = {
  production: true,
  apiUrl: 'https://docqr-api.geofal.com.pe/api',
  baseUrl: 'https://docqr.geofal.com.pe'
};
```

## 🧪 Pruebas

### Suite de Pruebas Básicas

1. ✅ Login con credenciales admin
2. ✅ Subir PDF pequeño (<5MB)
3. ✅ Subir PDF grande (>100MB)
4. ✅ Adjuntar PDF a documento
5. ✅ Editar posición de QR
6. ✅ Guardar cambios
7. ✅ Recargar página (F5) - verificar persistencia
8. ✅ Eliminar documento
9. ✅ Descargar código QR
10. ✅ Ver PDF final

### Verificación de Sistema

Ejecutar script de verificación:
```
https://docqr-api.geofal.com.pe/verificar_produccion.php
```

Este script verifica:
- Configuración de PHP
- Variables de entorno
- Permisos de directorios
- Conexión a base de datos
- Extensiones PHP requeridas

## 📊 Mantenimiento

### Tareas Programadas (Cron)

**Eliminar documentos antiguos (soft-deleted):**
```bash
# Ejecutar diariamente a las 2:00 AM
0 2 * * * /usr/bin/php /path/to/docqr-api/artisan documents:force-delete-old 30
```

### Logs

**Backend:**
```bash
tail -f docqr-api/storage/logs/laravel.log
```

**Base de datos:**
- Backups automáticos recomendados (diarios)
- Retención: 30 días mínimo

### Actualizaciones

**Backend:**
```bash
cd docqr-api
git pull
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

**Frontend:**
```bash
cd docqr-frontend
git pull
npm install
npm run build --prod
# Subir archivos actualizados
```

## 🔒 Seguridad

- ✅ HTTPS obligatorio en producción
- ✅ CORS configurado para dominios específicos
- ✅ Cookies seguras (SESSION_SECURE_COOKIE=true)
- ✅ APP_DEBUG=false en producción
- ✅ Validación de archivos PDF
- ✅ Rate limiting en API
- ✅ Protección contra XSS y CSRF
- ✅ Archivos sensibles (.env) protegidos

## 📞 Soporte y Solución de Problemas

### Problemas Comunes

**Error 500:**
```bash
chmod -R 775 storage bootstrap/cache
php artisan config:clear
php artisan cache:clear
```

**Error de CORS:**
```bash
# Verificar CORS_ALLOWED_ORIGINS en .env
php artisan config:cache
```

**Upload falla:**
```bash
# Verificar PHP
php -i | grep upload_max_filesize
# Debe mostrar: 500M
```

**Base de datos no conecta:**
```bash
# Usar 127.0.0.1 en vez de localhost
DB_HOST=127.0.0.1
```

### Documentación Adicional

- **Guía de Despliegue:** [`DESPLIEGUE_GEOFAL.md`](./DESPLIEGUE_GEOFAL.md)
- **Checklist:** [`CHECKLIST_GEOFAL.md`](./CHECKLIST_GEOFAL.md)
- **Script de Build:** [`build-production.sh`](./build-production.sh)

## 📈 Rendimiento

### Optimizaciones Implementadas

- ✅ Índices en base de datos
- ✅ Queries optimizadas con select específicos
- ✅ Sin cache para tiempo real
- ✅ Rate limiting API
- ✅ Compresión Gzip
- ✅ Lazy loading en frontend
- ✅ Code splitting

### Capacidad

- **Usuarios simultáneos:** 50+ (recomendado)
- **Tamaño máximo de PDF:** 500MB
- **Documentos en sistema:** Ilimitado (depende de almacenamiento)
- **Tiempo de procesamiento:** ~5-30 segundos (según tamaño de PDF)

## 🎯 Características Específicas de Geofal

### Subdominios

- Frontend: `docqr.geofal.com.pe`
- Backend API: `docqr-api.geofal.com.pe`

### Base de Datos

- Nombre: `geofal_docqr`
- Usuario: `geofal_docqr_user`
- Charset: `utf8mb4`
- Collation: `utf8mb4_unicode_ci`

### Almacenamiento

Estructura de directorios:
```
storage/app/
├── pdfs/           # PDFs originales
├── qrs/            # Códigos QR generados
└── final_pdfs/     # PDFs con QR integrado
```

## 📄 Licencia

Propietario: **Geofal**  
Desarrollado para uso interno exclusivo.

---

## 📞 Contacto

**Equipo de Desarrollo - Geofal**

Para reportar problemas o solicitar soporte:
1. Revisar logs: `storage/logs/laravel.log`
2. Ejecutar: `verificar_produccion.php`
3. Consultar: `DESPLIEGUE_GEOFAL.md`

---

**Versión:** 1.0.0  
**Última actualización:** 2025  
**Estado:** ✅ Producción

