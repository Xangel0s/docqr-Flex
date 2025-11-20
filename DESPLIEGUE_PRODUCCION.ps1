#!/usr/bin/env pwsh
# ============================================================
# SCRIPT DE DESPLIEGUE FINAL - DocQR Backend
# ============================================================
# Este script crea un ZIP listo para produccion
# INCLUYE: vendor/ (dependencias completas)
# EXCLUYE: archivos de desarrollo, logs, .env local
# ============================================================

Write-Host ""
Write-Host "==========================================" -ForegroundColor Cyan
Write-Host "   PREPARANDO BACKEND PARA PRODUCCION" -ForegroundColor Cyan
Write-Host "==========================================" -ForegroundColor Cyan

# Rutas
$backendDir = "docqr-api"
$outputZip = "docqr-api-PRODUCCION.zip"
$tempDir = "temp_deploy"

# Verificar directorio
if (-not (Test-Path $backendDir))
{
    Write-Host ""
    Write-Host "ERROR: No se encuentra $backendDir" -ForegroundColor Red
    exit 1
}

Write-Host ""
Write-Host "Preparando archivos..." -ForegroundColor Yellow

# Limpiar anterior
Remove-Item $outputZip -Force -ErrorAction SilentlyContinue
Remove-Item $tempDir -Recurse -Force -ErrorAction SilentlyContinue

# Crear temp
New-Item -ItemType Directory -Path $tempDir -Force | Out-Null

# Copiar todo
Write-Host "   Copiando archivos..." -ForegroundColor Gray
Copy-Item -Path "$backendDir\*" -Destination $tempDir -Recurse -Force

# Limpiar desarrollo
Write-Host ""
Write-Host "Limpiando archivos de desarrollo..." -ForegroundColor Yellow

$excludeItems = @(
    "node_modules",
    ".git",
    ".env",
    ".env.local",
    ".env.backup",
    ".env.testing",
    "storage\logs",
    "tests",
    ".gitignore",
    ".editorconfig",
    "phpunit.xml",
    "README.md"
)

foreach ($item in $excludeItems)
{
    $fullPath = Join-Path $tempDir $item
    if (Test-Path $fullPath)
    {
        Write-Host "   Eliminando: $item" -ForegroundColor Gray
        Remove-Item $fullPath -Recurse -Force -ErrorAction SilentlyContinue
    }
}

# Limpiar logs
$logsPath = Join-Path $tempDir "storage\logs"
if (Test-Path $logsPath)
{
    Get-ChildItem -Path $logsPath -Recurse -File | Remove-Item -Force -ErrorAction SilentlyContinue
}

# Crear .env.example para produccion
$envTemplate = @"
# COPIA ESTE CONTENIDO Y CREA .env EN EL SERVIDOR
APP_NAME="DocQR Geofal"
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL=https://docqr-api.geofal.com.pe

FRONTEND_URL=https://docqr.geofal.com.pe

DB_CONNECTION=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=
DB_USERNAME=
DB_PASSWORD=

CACHE_STORE=database
SESSION_DRIVER=database
QUEUE_CONNECTION=database
LOG_LEVEL=error
"@

$envTemplate | Out-File -FilePath (Join-Path $tempDir ".env.example") -Encoding UTF8

# Comprimir
Write-Host ""
Write-Host "Comprimiendo..." -ForegroundColor Yellow
Compress-Archive -Path "$tempDir\*" -DestinationPath $outputZip -Force

# Limpiar
Remove-Item $tempDir -Recurse -Force

# Resultado
if (Test-Path $outputZip)
{
    $zipInfo = Get-Item $outputZip
    $sizeMB = [math]::Round($zipInfo.Length / 1MB, 2)
    
    Write-Host ""
    Write-Host "==========================================" -ForegroundColor Green
    Write-Host "   BACKEND LISTO PARA PRODUCCION" -ForegroundColor Green
    Write-Host "==========================================" -ForegroundColor Green
    Write-Host ""
    Write-Host "Archivo: $outputZip" -ForegroundColor White
    Write-Host "Tamano: $sizeMB MB" -ForegroundColor Cyan
    Write-Host "Ruta: $($zipInfo.FullName)" -ForegroundColor Gray
    Write-Host ""
    Write-Host "QUE INCLUYE:" -ForegroundColor Green
    Write-Host "  vendor/ completo (Laravel, Sanctum, FPDI, TCPDF, etc.)" -ForegroundColor Gray
    Write-Host "  Codigo fuente PHP optimizado" -ForegroundColor Gray
    Write-Host "  Configuraciones de produccion" -ForegroundColor Gray
    Write-Host ""
    Write-Host "QUE NO INCLUYE (correcto):" -ForegroundColor Cyan
    Write-Host "  .env (debes crearlo en el servidor)" -ForegroundColor Gray
    Write-Host "  node_modules/ (innecesario)" -ForegroundColor Gray
    Write-Host "  tests/ (innecesario)" -ForegroundColor Gray
    Write-Host "  logs/ (se generan nuevos)" -ForegroundColor Gray
    Write-Host ""
    Write-Host "PASOS EN EL SERVIDOR:" -ForegroundColor Yellow
    Write-Host "  1. Sube el ZIP via cPanel/FTP" -ForegroundColor White
    Write-Host "  2. Extrae en el directorio raiz" -ForegroundColor White
    Write-Host "  3. Crea .env (usa ENV_PRODUCTION_TEMPLATE.txt)" -ForegroundColor White
    Write-Host "  4. php artisan key:generate" -ForegroundColor White
    Write-Host "  5. php artisan migrate --force" -ForegroundColor White
    Write-Host "  6. php artisan storage:link" -ForegroundColor White
    Write-Host "  7. php artisan optimize" -ForegroundColor White
    Write-Host "  8. chmod -R 775 storage bootstrap/cache" -ForegroundColor White
    Write-Host ""
    Write-Host "==========================================" -ForegroundColor Green
    Write-Host ""
}
else
{
    Write-Host ""
    Write-Host "ERROR: No se pudo crear el ZIP" -ForegroundColor Red
    exit 1
}

