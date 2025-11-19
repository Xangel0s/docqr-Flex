#!/bin/bash
# ====================================
# Script de Backup de Base de Datos
# DocQR-Flex
# ====================================
#
# USO:
# bash scripts/backup-database.sh
#
# CRON (diario a las 3 AM):
# 0 3 * * * /home/usuario/docqr-api/scripts/backup-database.sh >> /home/usuario/logs/backup.log 2>&1
#

set -e

# ====================================
# CONFIGURACIÓN
# ====================================

# Cargar variables de entorno
if [ -f .env ]; then
    source .env
else
    echo "Error: Archivo .env no encontrado"
    exit 1
fi

# Directorios
BACKUP_DIR="${BACKUP_PATH:-/home/usuario/backups}"
DATE=$(date +%Y%m%d-%H%M%S)
RETENTION_DAYS=${BACKUP_RETENTION_DAYS:-30}

# Archivos
DB_BACKUP_FILE="db-${DATE}.sql"
UPLOADS_BACKUP_FILE="uploads-${DATE}.tar.gz"
QR_BACKUP_FILE="qr-${DATE}.tar.gz"

# Colores
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

# ====================================
# FUNCIONES
# ====================================

print_success() {
    echo -e "${GREEN}✓ $1${NC}"
}

print_error() {
    echo -e "${RED}✗ $1${NC}"
}

print_info() {
    echo -e "${YELLOW}ℹ $1${NC}"
}

# ====================================
# INICIO
# ====================================

echo "======================================"
echo "Backup de DocQR-Flex"
echo "Fecha: $(date)"
echo "======================================"

# Crear directorio de backups si no existe
mkdir -p "${BACKUP_DIR}"

# ====================================
# 1. BACKUP DE BASE DE DATOS
# ====================================

print_info "Haciendo backup de base de datos..."

if mysqldump \
    -h "${DB_HOST}" \
    -u "${DB_USERNAME}" \
    -p"${DB_PASSWORD}" \
    "${DB_DATABASE}" \
    --single-transaction \
    --routines \
    --triggers \
    > "${BACKUP_DIR}/${DB_BACKUP_FILE}"; then
    
    # Comprimir
    gzip "${BACKUP_DIR}/${DB_BACKUP_FILE}"
    
    # Tamaño del archivo
    SIZE=$(du -h "${BACKUP_DIR}/${DB_BACKUP_FILE}.gz" | cut -f1)
    
    print_success "Base de datos: ${DB_BACKUP_FILE}.gz (${SIZE})"
else
    print_error "Error al hacer backup de base de datos"
    exit 1
fi

# ====================================
# 2. BACKUP DE ARCHIVOS UPLOADS
# ====================================

if [ -d "${UPLOAD_PATH}" ]; then
    print_info "Haciendo backup de uploads..."
    
    if tar -czf "${BACKUP_DIR}/${UPLOADS_BACKUP_FILE}" \
        -C "$(dirname ${UPLOAD_PATH})" \
        "$(basename ${UPLOAD_PATH})"; then
        
        SIZE=$(du -h "${BACKUP_DIR}/${UPLOADS_BACKUP_FILE}" | cut -f1)
        print_success "Uploads: ${UPLOADS_BACKUP_FILE} (${SIZE})"
    else
        print_error "Error al hacer backup de uploads"
    fi
else
    print_info "Directorio de uploads no existe, omitiendo..."
fi

# ====================================
# 3. BACKUP DE CÓDIGOS QR
# ====================================

if [ -d "${QR_PATH}" ]; then
    print_info "Haciendo backup de códigos QR..."
    
    if tar -czf "${BACKUP_DIR}/${QR_BACKUP_FILE}" \
        -C "$(dirname ${QR_PATH})" \
        "$(basename ${QR_PATH})"; then
        
        SIZE=$(du -h "${BACKUP_DIR}/${QR_BACKUP_FILE}" | cut -f1)
        print_success "QR: ${QR_BACKUP_FILE} (${SIZE})"
    else
        print_error "Error al hacer backup de QR"
    fi
else
    print_info "Directorio de QR no existe, omitiendo..."
fi

# ====================================
# 4. LIMPIEZA DE BACKUPS ANTIGUOS
# ====================================

print_info "Limpiando backups antiguos (>${RETENTION_DAYS} días)..."

# Contar backups eliminados
DELETED_COUNT=0

# Eliminar backups de BD antiguos
DELETED=$(find "${BACKUP_DIR}" -name "db-*.sql.gz" -mtime +${RETENTION_DAYS} -delete -print | wc -l)
DELETED_COUNT=$((DELETED_COUNT + DELETED))

# Eliminar backups de uploads antiguos
DELETED=$(find "${BACKUP_DIR}" -name "uploads-*.tar.gz" -mtime +${RETENTION_DAYS} -delete -print | wc -l)
DELETED_COUNT=$((DELETED_COUNT + DELETED))

# Eliminar backups de QR antiguos
DELETED=$(find "${BACKUP_DIR}" -name "qr-*.tar.gz" -mtime +${RETENTION_DAYS} -delete -print | wc -l)
DELETED_COUNT=$((DELETED_COUNT + DELETED))

if [ ${DELETED_COUNT} -gt 0 ]; then
    print_success "Eliminados ${DELETED_COUNT} backups antiguos"
else
    print_info "No hay backups antiguos para eliminar"
fi

# ====================================
# 5. RESUMEN
# ====================================

echo "======================================"
print_success "Backup completado"
echo ""
echo "Archivos generados:"
echo "  - ${DB_BACKUP_FILE}.gz"
[ -f "${BACKUP_DIR}/${UPLOADS_BACKUP_FILE}" ] && echo "  - ${UPLOADS_BACKUP_FILE}"
[ -f "${BACKUP_DIR}/${QR_BACKUP_FILE}" ] && echo "  - ${QR_BACKUP_FILE}"
echo ""
echo "Ubicación: ${BACKUP_DIR}"
echo "Retención: ${RETENTION_DAYS} días"

# Espacio usado
TOTAL_SIZE=$(du -sh "${BACKUP_DIR}" | cut -f1)
echo "Espacio total backups: ${TOTAL_SIZE}"
echo "======================================"

# ====================================
# 6. NOTIFICACIÓN POR EMAIL (OPCIONAL)
# ====================================

if [ ! -z "${BACKUP_NOTIFICATION_EMAIL}" ]; then
    print_info "Enviando notificación por email..."
    
    echo "Backup completado exitosamente: ${DATE}" | \
        mail -s "DocQR Backup OK" "${BACKUP_NOTIFICATION_EMAIL}"
fi

exit 0
