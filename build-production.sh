#!/bin/bash

# =====================================================
# Script de Compilación y Empaquetado para Producción
# DocQR - Geofal
# =====================================================
# Este script compila el frontend y backend, y genera
# un archivo ZIP listo para subir al servidor
# =====================================================

set -e  # Salir si hay algún error

# Colores para output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Función para imprimir con color
print_step() {
    echo -e "${BLUE}==>${NC} $1"
}

print_success() {
    echo -e "${GREEN}✓${NC} $1"
}

print_warning() {
    echo -e "${YELLOW}⚠${NC} $1"
}

print_error() {
    echo -e "${RED}✗${NC} $1"
}

# Banner
echo ""
echo "=========================================="
echo "  DocQR - Build de Producción"
echo "  Geofal - $(date '+%Y-%m-%d %H:%M:%S')"
echo "=========================================="
echo ""

# Verificar que estamos en el directorio correcto
if [ ! -d "docqr-frontend" ] || [ ! -d "docqr-api" ]; then
    print_error "Error: Debes ejecutar este script desde la raíz del proyecto docqr-Flex"
    exit 1
fi

# Crear directorio de build
BUILD_DIR="build-production-$(date '+%Y%m%d-%H%M%S')"
print_step "Creando directorio de build: $BUILD_DIR"
mkdir -p "$BUILD_DIR"
mkdir -p "$BUILD_DIR/frontend"
mkdir -p "$BUILD_DIR/backend"
print_success "Directorio creado"

# =====================================================
# FRONTEND
# =====================================================

echo ""
print_step "Compilando Frontend (Angular)..."
cd docqr-frontend

# Verificar Node.js
if ! command -v node &> /dev/null; then
    print_error "Node.js no está instalado"
    exit 1
fi

print_step "Instalando dependencias del frontend..."
npm install

print_step "Compilando Angular en modo producción..."
npm run build --prod

if [ ! -d "dist/docqr-frontend" ]; then
    print_error "Error: La compilación falló, no se generó dist/docqr-frontend"
    exit 1
fi

print_success "Frontend compilado exitosamente"

# Copiar archivos compilados
print_step "Copiando archivos del frontend al build..."
cp -r dist/docqr-frontend/* "../$BUILD_DIR/frontend/"
cp .htaccess.produccion "../$BUILD_DIR/frontend/.htaccess"
print_success "Archivos del frontend copiados"

cd ..

# =====================================================
# BACKEND
# =====================================================

echo ""
print_step "Preparando Backend (Laravel)..."
cd docqr-api

# Verificar Composer
if ! command -v composer &> /dev/null; then
    print_error "Composer no está instalado"
    exit 1
fi

print_step "Instalando dependencias del backend (producción)..."
composer install --no-dev --optimize-autoloader --prefer-dist

print_success "Dependencias del backend instaladas"

# Copiar archivos del backend
print_step "Copiando archivos del backend al build..."
rsync -av --progress . "../$BUILD_DIR/backend/" \
    --exclude=node_modules \
    --exclude=.git \
    --exclude=.env \
    --exclude=storage/logs/*.log \
    --exclude=storage/framework/cache/* \
    --exclude=storage/framework/sessions/* \
    --exclude=storage/framework/views/* \
    --exclude=storage/app/pdfs/* \
    --exclude=storage/app/qrs/* \
    --exclude=storage/app/final_pdfs/* \
    --exclude=vendor \
    --exclude=tests

# Reinstalar vendor en el build
print_step "Instalando vendor en el directorio de build..."
cd "../$BUILD_DIR/backend"
composer install --no-dev --optimize-autoloader --prefer-dist --no-scripts

cd ../..

print_success "Archivos del backend copiados"

# =====================================================
# ARCHIVOS DE CONFIGURACIÓN
# =====================================================

echo ""
print_step "Copiando archivos de configuración..."

# Copiar .env.production
cp docqr-api/.env.production "$BUILD_DIR/backend/.env.example"

# Copiar .user.ini
cp docqr-api/.user.ini.example "$BUILD_DIR/backend/.user.ini"

# Copiar .htaccess de producción
cp docqr-api/.htaccess.produccion "$BUILD_DIR/backend/public/.htaccess"

# Copiar scripts de verificación
cp docqr-api/public/verificar_produccion.php "$BUILD_DIR/backend/public/"
cp docqr-api/verificar_php_config.php "$BUILD_DIR/backend/" 2>/dev/null || true

# Copiar documentación
cp README.md "$BUILD_DIR/" 2>/dev/null || true
cp DESPLIEGUE_GEOFAL.md "$BUILD_DIR/" 2>/dev/null || true
cp CHECKLIST_GEOFAL.md "$BUILD_DIR/" 2>/dev/null || true

print_success "Archivos de configuración copiados"

# =====================================================
# CREAR DIRECTORIOS NECESARIOS
# =====================================================

echo ""
print_step "Creando estructura de directorios..."

# Crear directorios de storage
mkdir -p "$BUILD_DIR/backend/storage/app/pdfs"
mkdir -p "$BUILD_DIR/backend/storage/app/qrs"
mkdir -p "$BUILD_DIR/backend/storage/app/final_pdfs"
mkdir -p "$BUILD_DIR/backend/storage/logs"
mkdir -p "$BUILD_DIR/backend/storage/framework/cache"
mkdir -p "$BUILD_DIR/backend/storage/framework/sessions"
mkdir -p "$BUILD_DIR/backend/storage/framework/views"
mkdir -p "$BUILD_DIR/backend/bootstrap/cache"

# Crear archivos .gitignore en directorios vacíos
echo "*" > "$BUILD_DIR/backend/storage/app/pdfs/.gitignore"
echo "!.gitignore" >> "$BUILD_DIR/backend/storage/app/pdfs/.gitignore"

print_success "Estructura de directorios creada"

# =====================================================
# CREAR ARCHIVO DE INSTRUCCIONES
# =====================================================

echo ""
print_step "Creando archivo de instrucciones..."

cat > "$BUILD_DIR/INSTRUCCIONES_DESPLIEGUE.txt" << 'EOF'
========================================
INSTRUCCIONES DE DESPLIEGUE - DocQR
Geofal - Sistema de Gestión de Documentos con QR
========================================

ESTRUCTURA DE ARCHIVOS:
-----------------------
Este ZIP contiene:
- /frontend/          → Subir a public_html/docqr/
- /backend/           → Subir a public_html/docqr-api/
- DESPLIEGUE_GEOFAL.md → Guía completa de despliegue
- CHECKLIST_GEOFAL.md  → Checklist de verificación

SUBDOMINIOS NECESARIOS:
----------------------
1. docqr.geofal.com.pe       → Frontend (Angular)
2. docqr-api.geofal.com.pe   → Backend (Laravel API)

PASOS RÁPIDOS:
-------------

1. SUBIR ARCHIVOS:
   - Contenido de /frontend/ → public_html/docqr/
   - Contenido de /backend/  → public_html/docqr-api/

2. CONFIGURAR .ENV:
   cd public_html/docqr-api
   cp .env.example .env
   nano .env
   # Configurar:
   #   APP_KEY (generar con: php artisan key:generate)
   #   DB_DATABASE=geofal_docqr
   #   DB_USERNAME=tu_usuario
   #   DB_PASSWORD=tu_password

3. CONFIGURAR PHP (cPanel → MultiPHP INI Editor):
   upload_max_filesize = 500M
   post_max_size = 510M
   memory_limit = 1024M
   max_execution_time = 600

4. PERMISOS:
   chmod -R 775 storage bootstrap/cache

5. OPTIMIZAR LARAVEL:
   php artisan migrate --force
   php artisan config:cache
   php artisan route:cache
   php artisan view:cache
   php artisan optimize

6. CREAR USUARIO ADMIN:
   php database/scripts/crear_usuario_admin.php

7. INSTALAR CERTIFICADOS SSL:
   cPanel → SSL/TLS → Instalar para ambos subdominios

8. VERIFICAR:
   https://docqr-api.geofal.com.pe/verificar_produccion.php

DOCUMENTACIÓN COMPLETA:
----------------------
Consulta DESPLIEGUE_GEOFAL.md para instrucciones detalladas.

SOPORTE:
-------
En caso de problemas, revisa:
1. storage/logs/laravel.log
2. Ejecuta verificar_produccion.php
3. Consulta DESPLIEGUE_GEOFAL.md

URLS DE ACCESO:
--------------
Frontend: https://docqr.geofal.com.pe
Backend:  https://docqr-api.geofal.com.pe
Admin:    Usuario: admin | Contraseña: admin123

========================================
¡Éxito con el despliegue!
========================================
EOF

print_success "Archivo de instrucciones creado"

# =====================================================
# GENERAR CHECKSUM
# =====================================================

echo ""
print_step "Generando checksums..."
cd "$BUILD_DIR"
find . -type f -exec md5sum {} \; > CHECKSUMS.txt 2>/dev/null || find . -type f -exec md5 {} \; > CHECKSUMS.txt
cd ..
print_success "Checksums generados"

# =====================================================
# COMPRIMIR
# =====================================================

echo ""
print_step "Comprimiendo archivos..."

ZIP_NAME="docqr-geofal-production-$(date '+%Y%m%d-%H%M%S').zip"

if command -v zip &> /dev/null; then
    zip -r "$ZIP_NAME" "$BUILD_DIR" -q
    print_success "Archivo ZIP creado: $ZIP_NAME"
else
    print_warning "zip no está instalado, archivos en: $BUILD_DIR"
fi

# =====================================================
# INFORMACIÓN FINAL
# =====================================================

echo ""
echo "=========================================="
echo "  Build Completado Exitosamente"
echo "=========================================="
echo ""
print_success "Directorio de build: $BUILD_DIR"
if [ -f "$ZIP_NAME" ]; then
    print_success "Archivo ZIP: $ZIP_NAME"
    print_success "Tamaño: $(du -h "$ZIP_NAME" | cut -f1)"
fi
echo ""
print_step "Próximos pasos:"
echo "  1. Subir el archivo ZIP al servidor"
echo "  2. Extraer en las ubicaciones correctas"
echo "  3. Seguir instrucciones en INSTRUCCIONES_DESPLIEGUE.txt"
echo "  4. Consultar DESPLIEGUE_GEOFAL.md para detalles"
echo ""
print_warning "IMPORTANTE:"
echo "  - Configurar .env con credenciales reales"
echo "  - Generar APP_KEY con: php artisan key:generate"
echo "  - Configurar PHP para archivos de 500MB"
echo "  - Instalar certificados SSL"
echo ""
print_success "¡Listo para desplegar en producción!"
echo ""

