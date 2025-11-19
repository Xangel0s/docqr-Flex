# Script optimizado para crear ZIPs rapidamente
$ErrorActionPreference = "Stop"

Write-Host "`n========================================" -ForegroundColor Cyan
Write-Host "  GENERACION RAPIDA DE ARCHIVOS" -ForegroundColor Cyan
Write-Host "========================================`n" -ForegroundColor Cyan

# ZIP DEL BACKEND
Write-Host "[1/3] Creando ZIP del backend..." -ForegroundColor Yellow
$backendZip = "docqr-api-PRODUCTION-FINAL.zip"
if (Test-Path $backendZip) { Remove-Item $backendZip -Force }

$tempBackend = "temp_backend_zip"
if (Test-Path $tempBackend) { Remove-Item $tempBackend -Recurse -Force }
New-Item -ItemType Directory -Path $tempBackend | Out-Null

$backendFiles = @("app", "bootstrap", "config", "database", "public", "routes", "storage", ".env.example", "composer.json", "composer.lock")
foreach ($item in $backendFiles) {
    $src = "docqr-api\$item"
    if (Test-Path $src) {
        $dest = Join-Path $tempBackend $item
        if ((Get-Item $src).PSIsContainer) {
            Copy-Item -Path $src -Destination $dest -Recurse -Exclude "*.log","*.tmp","*.cache" -ErrorAction SilentlyContinue
        } else {
            Copy-Item -Path $src -Destination $dest -ErrorAction SilentlyContinue
        }
    }
}

# Crear estructura de storage
@("logs", "framework\cache", "framework\sessions", "framework\views") | ForEach-Object {
    $dir = Join-Path "$tempBackend\storage" $_
    New-Item -ItemType Directory -Path $dir -Force | Out-Null
    New-Item -ItemType File -Path "$dir\.gitkeep" -Force | Out-Null
}

Compress-Archive -Path "$tempBackend\*" -DestinationPath $backendZip -Force
Remove-Item $tempBackend -Recurse -Force
Write-Host "OK ZIP del backend creado: $backendZip" -ForegroundColor Green

# ZIP DEL FRONTEND
Write-Host "`n[2/3] Creando ZIP del frontend..." -ForegroundColor Yellow
$frontendZip = "docqr-frontend-PRODUCTION-FINAL.zip"
$frontendDist = "docqr-frontend\dist\docqr-frontend"
if (Test-Path $frontendZip) { Remove-Item $frontendZip -Force }
if (Test-Path $frontendDist) {
    Compress-Archive -Path "$frontendDist\*" -DestinationPath $frontendZip -Force
    Write-Host "OK ZIP del frontend creado: $frontendZip" -ForegroundColor Green
} else {
    Write-Host "ERROR: No se encontro el build del frontend" -ForegroundColor Red
}

# SQL
Write-Host "`n[3/3] Preparando SQL..." -ForegroundColor Yellow
$envFile = "docqr-api\.env"
$sqlFile = "docqr-database.sql"
if (Test-Path $envFile) {
    $envContent = Get-Content $envFile
    $dbName = ($envContent | Select-String "DB_DATABASE=(.+)" | ForEach-Object { $_.Matches.Groups[1].Value }) -replace '"', '' -replace "'", ''
    $dbUser = ($envContent | Select-String "DB_USERNAME=(.+)" | ForEach-Object { $_.Matches.Groups[1].Value }) -replace '"', '' -replace "'", ''
    $dbHost = ($envContent | Select-String "DB_HOST=(.+)" | ForEach-Object { $_.Matches.Groups[1].Value }) -replace '"', '' -replace "'", ''
    if (-not $dbName) { $dbName = "docqr_db" }
    if (-not $dbUser) { $dbUser = "root" }
    if (-not $dbHost) { $dbHost = "localhost" }
    
    $sqlContent = "-- Base de datos: $dbName`n-- Generado automaticamente`n`nCREATE DATABASE IF NOT EXISTS ``$dbName`` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;`nUSE ``$dbName``;`n`n-- Exporta datos completos desde phpMyAdmin o con: mysqldump -h $dbHost -u $dbUser -p $dbName > completo.sql`n"
    $sqlContent | Out-File -FilePath $sqlFile -Encoding UTF8
    Write-Host "OK Archivo SQL creado: $sqlFile" -ForegroundColor Green
}

# RESUMEN
Write-Host "`n========================================" -ForegroundColor Cyan
Write-Host "  RESUMEN" -ForegroundColor Cyan
Write-Host "========================================`n" -ForegroundColor Cyan
if (Test-Path $backendZip) {
    $sz = [math]::Round((Get-Item $backendZip).Length / 1MB, 2)
    Write-Host "  $backendZip - $sz MB" -ForegroundColor White
}
if (Test-Path $frontendZip) {
    $sz = [math]::Round((Get-Item $frontendZip).Length / 1MB, 2)
    Write-Host "  $frontendZip - $sz MB" -ForegroundColor White
}
if (Test-Path $sqlFile) {
    Write-Host "  $sqlFile" -ForegroundColor White
}
Write-Host "`nNOTA: El backend NO incluye vendor/ (ejecuta composer install en el servidor)" -ForegroundColor Yellow
Write-Host "`nProceso completado!" -ForegroundColor Green

