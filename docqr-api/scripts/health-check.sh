#!/bin/bash
# ====================================
# Health Check Script
# DocQR-Flex
# ====================================
#
# USO:
# bash scripts/health-check.sh
#
# CRON (cada 5 minutos):
# */5 * * * * /home/usuario/docqr-api/scripts/health-check.sh >> /home/usuario/logs/health.log 2>&1
#

set -e

# ====================================
# CONFIGURACIÓN
# ====================================

# URL de la aplicación
APP_URL="${APP_URL:-https://docqr.geofal.com.pe}"
HEALTH_ENDPOINT="${APP_URL}/up"

# Timeouts
TIMEOUT=10

# Email para alertas (configurar en .env)
ALERT_EMAIL="${BACKUP_NOTIFICATION_EMAIL:-}"

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

print_warning() {
    echo -e "${YELLOW}⚠ $1${NC}"
}

send_alert() {
    local message="$1"
    
    if [ ! -z "${ALERT_EMAIL}" ]; then
        echo "${message}" | mail -s "DocQR Alert - $(date)" "${ALERT_EMAIL}"
    fi
}

# ====================================
# CHECKS
# ====================================

TIMESTAMP=$(date '+%Y-%m-%d %H:%M:%S')
FAILED_CHECKS=0

echo "======================================"
echo "Health Check - ${TIMESTAMP}"
echo "======================================"

# ====================================
# 1. CHECK HTTP
# ====================================

echo -n "Verificando endpoint HTTP... "

HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" --max-time ${TIMEOUT} "${HEALTH_ENDPOINT}" || echo "000")

if [ "${HTTP_CODE}" == "200" ]; then
    print_success "OK (HTTP ${HTTP_CODE})"
else
    print_error "FAILED (HTTP ${HTTP_CODE})"
    send_alert "Aplicación no responde. HTTP Code: ${HTTP_CODE}"
    FAILED_CHECKS=$((FAILED_CHECKS + 1))
fi

# ====================================
# 2. CHECK RESPONSE TIME
# ====================================

echo -n "Verificando tiempo de respuesta... "

RESPONSE_TIME=$(curl -s -o /dev/null -w "%{time_total}" --max-time ${TIMEOUT} "${HEALTH_ENDPOINT}" || echo "999")

# Convertir a milisegundos
RESPONSE_MS=$(echo "${RESPONSE_TIME} * 1000" | bc | cut -d. -f1)

if [ ${RESPONSE_MS} -lt 1000 ]; then
    print_success "${RESPONSE_MS}ms"
elif [ ${RESPONSE_MS} -lt 3000 ]; then
    print_warning "${RESPONSE_MS}ms (lento)"
else
    print_error "${RESPONSE_MS}ms (muy lento)"
    send_alert "Aplicación respondiendo lento: ${RESPONSE_MS}ms"
    FAILED_CHECKS=$((FAILED_CHECKS + 1))
fi

# ====================================
# 3. CHECK SSL
# ====================================

if [[ "${APP_URL}" == https* ]]; then
    echo -n "Verificando certificado SSL... "
    
    SSL_EXPIRY=$(echo | openssl s_client -servername "${APP_URL#https://}" -connect "${APP_URL#https://}:443" 2>/dev/null | openssl x509 -noout -enddate 2>/dev/null | cut -d= -f2)
    
    if [ ! -z "${SSL_EXPIRY}" ]; then
        SSL_EXPIRY_EPOCH=$(date -d "${SSL_EXPIRY}" +%s 2>/dev/null || echo "0")
        NOW_EPOCH=$(date +%s)
        DAYS_LEFT=$(( (SSL_EXPIRY_EPOCH - NOW_EPOCH) / 86400 ))
        
        if [ ${DAYS_LEFT} -gt 30 ]; then
            print_success "OK (${DAYS_LEFT} días restantes)"
        elif [ ${DAYS_LEFT} -gt 7 ]; then
            print_warning "Expira pronto (${DAYS_LEFT} días)"
            send_alert "Certificado SSL expira en ${DAYS_LEFT} días"
        else
            print_error "Expira muy pronto (${DAYS_LEFT} días)"
            send_alert "URGENTE: Certificado SSL expira en ${DAYS_LEFT} días"
            FAILED_CHECKS=$((FAILED_CHECKS + 1))
        fi
    else
        print_error "No se pudo verificar"
        FAILED_CHECKS=$((FAILED_CHECKS + 1))
    fi
fi

# ====================================
# 4. CHECK DISK SPACE
# ====================================

echo -n "Verificando espacio en disco... "

DISK_USAGE=$(df -h / | awk 'NR==2 {print $5}' | sed 's/%//')

if [ ${DISK_USAGE} -lt 80 ]; then
    print_success "${DISK_USAGE}% usado"
elif [ ${DISK_USAGE} -lt 90 ]; then
    print_warning "${DISK_USAGE}% usado"
    send_alert "Espacio en disco bajo: ${DISK_USAGE}%"
else
    print_error "${DISK_USAGE}% usado (crítico)"
    send_alert "URGENTE: Espacio en disco crítico: ${DISK_USAGE}%"
    FAILED_CHECKS=$((FAILED_CHECKS + 1))
fi

# ====================================
# 5. CHECK DATABASE
# ====================================

if [ -f .env ]; then
    source .env
    
    echo -n "Verificando conexión a base de datos... "
    
    if mysql -h "${DB_HOST}" -u "${DB_USERNAME}" -p"${DB_PASSWORD}" -e "USE ${DB_DATABASE};" 2>/dev/null; then
        print_success "OK"
    else
        print_error "No se puede conectar"
        send_alert "No se puede conectar a la base de datos"
        FAILED_CHECKS=$((FAILED_CHECKS + 1))
    fi
fi

# ====================================
# 6. CHECK LOGS
# ====================================

if [ -f storage/logs/laravel.log ]; then
    echo -n "Verificando errores recientes en logs... "
    
    # Contar errores en la última hora
    ERROR_COUNT=$(grep -i "error\|exception\|fatal" storage/logs/laravel.log | tail -100 | wc -l)
    
    if [ ${ERROR_COUNT} -eq 0 ]; then
        print_success "Sin errores"
    elif [ ${ERROR_COUNT} -lt 10 ]; then
        print_warning "${ERROR_COUNT} errores encontrados"
    else
        print_error "${ERROR_COUNT} errores (revisar logs)"
        send_alert "Múltiples errores detectados en logs: ${ERROR_COUNT}"
        FAILED_CHECKS=$((FAILED_CHECKS + 1))
    fi
fi

# ====================================
# RESUMEN
# ====================================

echo "======================================"

if [ ${FAILED_CHECKS} -eq 0 ]; then
    print_success "Todos los checks pasaron (${FAILED_CHECKS} fallos)"
    exit 0
else
    print_error "${FAILED_CHECKS} checks fallaron"
    exit 1
fi
