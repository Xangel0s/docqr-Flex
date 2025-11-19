#!/bin/bash
# ====================================
# Docker Entrypoint Script
# DocQR-Flex API
# ====================================

set -e

echo "======================================"
echo "DocQR-Flex API - Iniciando..."
echo "======================================"

# Esperar a que la base de datos esté lista
echo "Esperando conexión con base de datos..."
until php artisan db:show 2>/dev/null; do
  echo "Base de datos no disponible - esperando..."
  sleep 2
done

echo "✓ Conexión con base de datos establecida"

# Verificar si .env existe
if [ ! -f .env ]; then
    echo "⚠ Archivo .env no encontrado. Copiando desde .env.example..."
    cp .env.example .env
    php artisan key:generate
fi

# Cachear configuración en producción
if [ "$APP_ENV" = "production" ]; then
    echo "Optimizando para producción..."
    
    # Limpiar caches previos
    php artisan config:clear
    php artisan route:clear
    php artisan view:clear
    
    # Cachear todo
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
    
    echo "✓ Optimizaciones aplicadas"
fi

# Ejecutar migraciones (solo en primera ejecución o con variable)
if [ "$RUN_MIGRATIONS" = "true" ]; then
    echo "Ejecutando migraciones de base de datos..."
    php artisan migrate --force
    echo "✓ Migraciones completadas"
fi

# Crear enlace simbólico de storage (si no existe)
if [ ! -L public/storage ]; then
    echo "Creando enlace simbólico de storage..."
    php artisan storage:link
    echo "✓ Enlace simbólico creado"
fi

# Verificar permisos
echo "Verificando permisos de directorios..."
chmod -R 775 storage bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache
echo "✓ Permisos verificados"

echo "======================================"
echo "DocQR-Flex API - Listo para usar"
echo "======================================"

# Ejecutar comando principal (Apache)
exec "$@"
