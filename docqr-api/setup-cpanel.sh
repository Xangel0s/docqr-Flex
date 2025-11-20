#!/bin/bash

# =====================================================
# Script de Configuración Automática para cPanel
# DocQR - Geofal
# =====================================================
# Este script automatiza la instalación completa en cPanel
# =====================================================

# Colores para output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Detectar ruta de PHP (ajustar según tu servidor)
# Rutas comunes en cPanel:
PHP_PATHS=(
    "/opt/cpanel/ea-php81/root/usr/bin/php"
    "/opt/cpanel/ea-php82/root/usr/bin/php"
    "/opt/cpanel/ea-php80/root/usr/bin/php"
    "/usr/local/bin/php"
    "/usr/bin/php"
)

PHP=""
for path in "${PHP_PATHS[@]}"; do
    if [ -f "$path" ]; then
        PHP="$path"
        break
    fi
done

if [ -z "$PHP" ]; then
    echo -e "${RED}Error: No se encontró PHP en las rutas comunes${NC}"
    echo "Por favor, especifica la ruta manualmente:"
    echo "export PHP=/ruta/a/php"
    exit 1
fi

# Verificar versión de PHP
PHP_VERSION=$($PHP -r "echo PHP_VERSION;")
echo -e "${BLUE}==>${NC} Usando PHP $PHP_VERSION en: $PHP"

# Verificar que estamos en el directorio correcto
if [ ! -f "artisan" ]; then
    echo -e "${RED}Error: Este script debe ejecutarse desde la raíz de docqr-api${NC}"
    exit 1
fi

echo ""
echo "=========================================="
echo "  Setup DocQR para cPanel - Geofal"
echo "=========================================="
echo ""

# Paso 1: Verificar .env
echo -e "${BLUE}==>${NC} Verificando archivo .env..."
if [ ! -f ".env" ]; then
    if [ -f ".env.production" ]; then
        echo -e "${YELLOW}⚠${NC} .env no existe, copiando desde .env.production"
        cp .env.production .env
        echo -e "${GREEN}✓${NC} .env creado"
        echo -e "${YELLOW}⚠${NC} IMPORTANTE: Edita .env con tus credenciales de base de datos"
        echo ""
        read -p "Presiona Enter cuando hayas configurado .env..."
    else
        echo -e "${RED}✗${NC} Error: No se encontró .env ni .env.production"
        exit 1
    fi
else
    echo -e "${GREEN}✓${NC} .env existe"
fi

# Paso 2: Instalar dependencias
echo ""
echo -e "${BLUE}==>${NC} Instalando dependencias de Composer..."
if command -v composer &> /dev/null; then
    $PHP $(which composer) install --no-dev --optimize-autoloader
else
    echo -e "${YELLOW}⚠${NC} Composer no encontrado en PATH, intentando ruta común..."
    if [ -f "/usr/local/bin/composer" ]; then
        $PHP /usr/local/bin/composer install --no-dev --optimize-autoloader
    else
        echo -e "${RED}✗${NC} Error: Composer no encontrado"
        echo "Descarga composer.phar y ejecuta:"
        echo "$PHP composer.phar install --no-dev --optimize-autoloader"
        exit 1
    fi
fi
echo -e "${GREEN}✓${NC} Dependencias instaladas"

# Paso 3: Generar APP_KEY si no existe
echo ""
echo -e "${BLUE}==>${NC} Verificando APP_KEY..."
if ! grep -q "APP_KEY=base64:" .env; then
    echo -e "${YELLOW}⚠${NC} Generando APP_KEY..."
    $PHP artisan key:generate
    echo -e "${GREEN}✓${NC} APP_KEY generada"
else
    echo -e "${GREEN}✓${NC} APP_KEY ya existe"
fi

# Paso 4: Ejecutar migraciones
echo ""
echo -e "${BLUE}==>${NC} Ejecutando migraciones de base de datos..."
$PHP artisan migrate --force
if [ $? -eq 0 ]; then
    echo -e "${GREEN}✓${NC} Migraciones completadas"
else
    echo -e "${RED}✗${NC} Error en migraciones. Verifica las credenciales de BD en .env"
    exit 1
fi

# Paso 5: Configurar permisos
echo ""
echo -e "${BLUE}==>${NC} Configurando permisos..."
chmod -R 775 storage bootstrap/cache
echo -e "${GREEN}✓${NC} Permisos configurados"

# Paso 6: Optimizar Laravel
echo ""
echo -e "${BLUE}==>${NC} Optimizando Laravel..."
$PHP artisan config:cache
$PHP artisan route:cache
$PHP artisan view:cache
$PHP artisan optimize
echo -e "${GREEN}✓${NC} Optimización completada"

# Paso 7: Crear usuario admin
echo ""
echo -e "${BLUE}==>${NC} Creando usuario administrador..."
if [ -f "database/scripts/crear_usuario_admin.php" ]; then
    $PHP database/scripts/crear_usuario_admin.php
    echo -e "${GREEN}✓${NC} Usuario admin creado"
    echo -e "${YELLOW}Credenciales:${NC} admin / admin123"
else
    echo -e "${YELLOW}⚠${NC} Script de usuario admin no encontrado"
fi

# Paso 8: Verificar estructura de directorios
echo ""
echo -e "${BLUE}==>${NC} Verificando estructura de directorios..."
mkdir -p storage/app/pdfs
mkdir -p storage/app/qrs
mkdir -p storage/app/final_pdfs
mkdir -p storage/logs
mkdir -p storage/framework/cache
mkdir -p storage/framework/sessions
mkdir -p storage/framework/views
mkdir -p bootstrap/cache
echo -e "${GREEN}✓${NC} Estructura de directorios verificada"

# Resumen final
echo ""
echo "=========================================="
echo "  ✓ Instalación Completada"
echo "=========================================="
echo ""
echo -e "${GREEN}✓${NC} PHP: $PHP_VERSION"
echo -e "${GREEN}✓${NC} Dependencias instaladas"
echo -e "${GREEN}✓${NC} Base de datos migrada"
echo -e "${GREEN}✓${NC} Permisos configurados"
echo -e "${GREEN}✓${NC} Laravel optimizado"
echo ""
echo -e "${BLUE}Próximos pasos:${NC}"
echo "1. Cambiar contraseña de admin después del primer login"
echo "2. Configurar SSL/HTTPS para el dominio"
echo "3. Verificar: https://docqr-api.geofal.com.pe/verificar_produccion.php"
echo ""
echo -e "${GREEN}¡Sistema listo para usar!${NC}"
echo ""

