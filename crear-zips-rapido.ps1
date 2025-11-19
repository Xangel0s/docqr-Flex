# Script optimizado para crear ZIPs rápidamente
# Excluye node_modules, vendor (se instala en servidor), y archivos temporales

$ErrorActionPreference = "Stop"

Write-Host "`n========================================" -ForegroundColor Cyan
Write-Host "  GENERACIÓN RÁPIDA DE ARCHIVOS" -ForegroundColor Cyan
Write-Host "========================================`n" -ForegroundColor Cyan

# ============================================
# 1. ZIP DEL BACKEND (SIN VENDOR)
# ============================================
Write-Host "[1/3] Creando ZIP del backend (sin vendor)..." -ForegroundColor Yellow
$backendZip = "docqr-api-PRODUCTION.zip"

if (Test-Path $backendZip) {
    Remove-Item $backendZip -Force
}

# Usar Compress-Archive (más rápido pero menos control)
$backendFiles = @(
    "docqr-api\app",
    "docqr-api\bootstrap",
    "docqr-api\config",
    "docqr-api\database",
    "docqr-api\public",
    "docqr-api\routes",
    "docqr-api\storage\app",
    "docqr-api\storage\framework",
    "docqr-api\.env.example",
    "docqr-api\composer.json",
    "docqr-api\composer.lock"
)

# Crear carpeta temporal
$tempBackend = "temp_backend_zip"
if (Test-Path $tempBackend) {
    Remove-Item $tempBackend -Recurse -Force
}
New-Item -ItemType Directory -Path $tempBackend | Out-Null

# Copiar solo archivos necesarios (sin vendor, sin logs)
foreach ($item in $backendFiles) {
    if (Test-Path $item) {
        $dest = Join-Path $tempBackend (Split-Path $item -Leaf)
        if ((Get-Item $item).PSIsContainer) {
            Copy-Item -Path $item -Destination $dest -Recurse -Exclude "*.log","*.tmp","*.cache" -ErrorAction SilentlyContinue
        } else {
            Copy-Item -Path $item -Destination $dest -ErrorAction SilentlyContinue
        }
    }
}

# Crear estructura de storage
New-Item -ItemType Directory -Path "$tempBackend\storage\logs" -Force | Out-Null
New-Item -ItemType Directory -Path "$tempBackend\storage\framework\cache" -Force | Out-Null
New-Item -ItemType Directory -Path "$tempBackend\storage\framework\sessions" -Force | Out-Null
New-Item -ItemType Directory -Path "$tempBackend\storage\framework\views" -Force | Out-Null

# Crear .gitkeep en carpetas vacías
@("logs", "framework\cache", "framework\sessions", "framework\views") | ForEach-Object {
    $gitkeep = Join-Path "$tempBackend\storage" "$_\\.gitkeep"
    New-Item -ItemType File -Path $gitkeep -Force | Out-Null
}

# Comprimir
Compress-Archive -Path "$tempBackend\*" -DestinationPath $backendZip -Force
Remove-Item $tempBackend -Recurse -Force

Write-Host "✅ ZIP del backend creado: $backendZip" -ForegroundColor Green

# ============================================
# 2. ZIP DEL FRONTEND COMPILADO
# ============================================
Write-Host "`n[2/3] Creando ZIP del frontend compilado..." -ForegroundColor Yellow
$frontendZip = "docqr-frontend-PRODUCTION.zip"
$frontendDist = "docqr-frontend\dist\docqr-frontend"

if (Test-Path $frontendZip) {
    Remove-Item $frontendZip -Force
}

if (Test-Path $frontendDist) {
    Compress-Archive -Path "$frontendDist\*" -DestinationPath $frontendZip -Force
    Write-Host "✅ ZIP del frontend creado: $frontendZip" -ForegroundColor Green
} else {
    Write-Host "❌ ERROR: No se encontró el build del frontend" -ForegroundColor Red
}

# ============================================
# 3. EXPORTAR BASE DE DATOS
# ============================================
Write-Host "`n[3/3] Preparando exportación de base de datos..." -ForegroundColor Yellow

$envFile = "docqr-api\.env"
$sqlFile = "docqr-database.sql"

if (Test-Path $envFile) {
    $envContent = Get-Content $envFile
    $dbName = ($envContent | Select-String "DB_DATABASE=(.+)" | ForEach-Object { $_.Matches.Groups[1].Value }) -replace '"', '' -replace "'", ''
    $dbUser = ($envContent | Select-String "DB_USERNAME=(.+)" | ForEach-Object { $_.Matches.Groups[1].Value }) -replace '"', '' -replace "'", ''
    $dbPass = ($envContent | Select-String "DB_PASSWORD=(.+)" | ForEach-Object { $_.Matches.Groups[1].Value }) -replace '"', '' -replace "'", ''
    $dbHost = ($envContent | Select-String "DB_HOST=(.+)" | ForEach-Object { $_.Matches.Groups[1].Value }) -replace '"', '' -replace "'", ''
    
    if (-not $dbName) { $dbName = "docqr_db" }
    if (-not $dbUser) { $dbUser = "root" }
    if (-not $dbPass) { $dbPass = "" }
    if (-not $dbHost) { $dbHost = "localhost" }
    
    # Crear script SQL básico con estructura
    $sqlContent = @'
-- Base de datos: {0}
-- Generado automáticamente para producción
-- IMPORTANTE: Ejecuta este script en tu servidor MySQL/MariaDB

CREATE DATABASE IF NOT EXISTS `{0}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `{0}`;

-- NOTA: Este es un archivo de estructura básica.
-- Para exportar los datos completos, ejecuta en el servidor:
-- mysqldump -h {1} -u {2} -p {0} > docqr-database-completo.sql
-- O usa phpMyAdmin: Exportar -> SQL
'@ -f $dbName, $dbHost, $dbUser
    
    $sqlContent | Out-File -FilePath $sqlFile -Encoding UTF8
    Write-Host "✅ Archivo SQL de estructura creado: $sqlFile" -ForegroundColor Green
    Write-Host "   Para exportar datos completos, usa phpMyAdmin o mysqldump" -ForegroundColor Yellow
} else {
    Write-Host "⚠️  No se encontró .env. Crea el archivo SQL manualmente." -ForegroundColor Yellow
}

# ============================================
# RESUMEN
# ============================================
Write-Host "`n========================================" -ForegroundColor Cyan
Write-Host "  RESUMEN" -ForegroundColor Cyan
Write-Host "========================================`n" -ForegroundColor Cyan

Write-Host "Archivos generados:" -ForegroundColor Green
if (Test-Path $backendZip) {
    $backendSize = [math]::Round((Get-Item $backendZip).Length / 1MB, 2)
    Write-Host "   - $backendZip - $backendSize MB" -ForegroundColor White
}
if (Test-Path $frontendZip) {
    $frontendSize = [math]::Round((Get-Item $frontendZip).Length / 1MB, 2)
    Write-Host "   - $frontendZip - $frontendSize MB" -ForegroundColor White
}
if (Test-Path $sqlFile) {
    Write-Host "   - $sqlFile - estructura basica" -ForegroundColor White
}

Write-Host "`nIMPORTANTE:" -ForegroundColor Yellow
Write-Host "   - El backend NO incluye vendor/ (ejecuta 'composer install' en el servidor)" -ForegroundColor White
Write-Host "   - El backend NO incluye node_modules/ (solo frontend compilado)" -ForegroundColor White
Write-Host "   - Exporta la BD completa desde phpMyAdmin o con mysqldump" -ForegroundColor White

Write-Host "`nProceso completado!" -ForegroundColor Green

