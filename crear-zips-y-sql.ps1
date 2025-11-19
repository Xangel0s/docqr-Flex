# Script para crear ZIPs del backend, frontend y exportar base de datos SQL
# Ejecutar desde la raíz del proyecto

$ErrorActionPreference = "Stop"

Write-Host "`n========================================" -ForegroundColor Cyan
Write-Host "  GENERACIÓN DE ARCHIVOS PARA PRODUCCIÓN" -ForegroundColor Cyan
Write-Host "========================================`n" -ForegroundColor Cyan

# ============================================
# 1. VERIFICAR QUE EL BUILD DEL FRONTEND ESTÉ LISTO
# ============================================
Write-Host "[1/4] Verificando build del frontend..." -ForegroundColor Yellow
$frontendDist = "docqr-frontend\dist\docqr-frontend"
if (-not (Test-Path $frontendDist)) {
    Write-Host "❌ ERROR: No se encontró el build del frontend en $frontendDist" -ForegroundColor Red
    Write-Host "   Ejecuta primero: cd docqr-frontend && npm run build -- --configuration=production" -ForegroundColor Yellow
    exit 1
}
Write-Host "✅ Build del frontend encontrado" -ForegroundColor Green

# ============================================
# 2. CREAR ZIP DEL BACKEND
# ============================================
Write-Host "`n[2/4] Creando ZIP del backend..." -ForegroundColor Yellow
$backendZip = "docqr-api-PRODUCTION.zip"

# Archivos y carpetas a incluir en el backend
$backendInclude = @(
    "docqr-api\app",
    "docqr-api\bootstrap",
    "docqr-api\config",
    "docqr-api\database",
    "docqr-api\public",
    "docqr-api\routes",
    "docqr-api\storage",
    "docqr-api\vendor",
    "docqr-api\.env.example",
    "docqr-api\composer.json",
    "docqr-api\composer.lock"
)

# Archivos a excluir
$backendExclude = @(
    "docqr-api\storage\logs\*",
    "docqr-api\storage\framework\cache\*",
    "docqr-api\storage\framework\sessions\*",
    "docqr-api\storage\framework\views\*",
    "docqr-api\vendor\*\.git\*",
    "docqr-api\*.log"
)

try {
    if (Test-Path $backendZip) {
        Remove-Item $backendZip -Force
    }
    
    Add-Type -AssemblyName System.IO.Compression.FileSystem
    $zip = [System.IO.Compression.ZipFile]::Open((Resolve-Path .).Path + "\$backendZip", [System.IO.Compression.ZipArchiveMode]::Create)
    
    foreach ($item in $backendInclude) {
        if (Test-Path $item) {
            if ((Get-Item $item).PSIsContainer) {
                # Es una carpeta
                $files = Get-ChildItem -Path $item -Recurse -File
                foreach ($file in $files) {
                    $relativePath = $file.FullName.Replace((Resolve-Path .).Path + "\", "")
                    $shouldExclude = $false
                    foreach ($excludePattern in $backendExclude) {
                        if ($relativePath -like $excludePattern) {
                            $shouldExclude = $true
                            break
                        }
                    }
                    if (-not $shouldExclude) {
                        [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile($zip, $file.FullName, $relativePath) | Out-Null
                    }
                }
            } else {
                # Es un archivo
                $relativePath = $item.Replace((Resolve-Path .).Path + "\", "")
                [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile($zip, (Resolve-Path $item).Path, $relativePath) | Out-Null
            }
        }
    }
    
    $zip.Dispose()
    Write-Host "✅ ZIP del backend creado: $backendZip" -ForegroundColor Green
} catch {
    Write-Host "❌ Error al crear ZIP del backend: $_" -ForegroundColor Red
    exit 1
}

# ============================================
# 3. CREAR ZIP DEL FRONTEND COMPILADO
# ============================================
Write-Host "`n[3/4] Creando ZIP del frontend compilado..." -ForegroundColor Yellow
$frontendZip = "docqr-frontend-PRODUCTION.zip"

try {
    if (Test-Path $frontendZip) {
        Remove-Item $frontendZip -Force
    }
    
    Add-Type -AssemblyName System.IO.Compression.FileSystem
    [System.IO.Compression.ZipFile]::CreateFromDirectory((Resolve-Path $frontendDist).Path, (Resolve-Path .).Path + "\$frontendZip")
    
    Write-Host "✅ ZIP del frontend creado: $frontendZip" -ForegroundColor Green
} catch {
    Write-Host "❌ Error al crear ZIP del frontend: $_" -ForegroundColor Red
    exit 1
}

# ============================================
# 4. EXPORTAR BASE DE DATOS SQL
# ============================================
Write-Host "`n[4/4] Exportando base de datos SQL..." -ForegroundColor Yellow

# Leer configuración de .env del backend
$envFile = "docqr-api\.env"
if (-not (Test-Path $envFile)) {
    Write-Host "⚠️  No se encontró .env, usando valores por defecto" -ForegroundColor Yellow
    $dbName = "docqr_db"
    $dbUser = "root"
    $dbPass = ""
    $dbHost = "localhost"
} else {
    $envContent = Get-Content $envFile
    $dbName = ($envContent | Select-String "DB_DATABASE=(.+)" | ForEach-Object { $_.Matches.Groups[1].Value }) -replace '"', ''
    $dbUser = ($envContent | Select-String "DB_USERNAME=(.+)" | ForEach-Object { $_.Matches.Groups[1].Value }) -replace '"', ''
    $dbPass = ($envContent | Select-String "DB_PASSWORD=(.+)" | ForEach-Object { $_.Matches.Groups[1].Value }) -replace '"', ''
    $dbHost = ($envContent | Select-String "DB_HOST=(.+)" | ForEach-Object { $_.Matches.Groups[1].Value }) -replace '"', ''
    
    if (-not $dbName) { $dbName = "docqr_db" }
    if (-not $dbUser) { $dbUser = "root" }
    if (-not $dbPass) { $dbPass = "" }
    if (-not $dbHost) { $dbHost = "localhost" }
}

$sqlFile = "docqr-database.sql"

# Intentar exportar con mysqldump
$mysqldumpPath = "C:\xampp\mysql\bin\mysqldump.exe"
if (-not (Test-Path $mysqldumpPath)) {
    # Intentar otras ubicaciones comunes
    $possiblePaths = @(
        "C:\Program Files\MySQL\MySQL Server 8.0\bin\mysqldump.exe",
        "C:\Program Files\MySQL\MySQL Server 5.7\bin\mysqldump.exe",
        "mysqldump.exe"  # Si está en PATH
    )
    foreach ($path in $possiblePaths) {
        if (Test-Path $path) {
            $mysqldumpPath = $path
            break
        }
    }
}

if (Test-Path $mysqldumpPath) {
    try {
        $passwordArg = if ($dbPass) { "-p$dbPass" } else { "" }
        $command = "& `"$mysqldumpPath`" -h $dbHost -u $dbUser $passwordArg $dbName > `"$sqlFile`""
        Invoke-Expression $command
        
        if (Test-Path $sqlFile -and (Get-Item $sqlFile).Length -gt 0) {
            Write-Host "✅ Base de datos exportada: $sqlFile" -ForegroundColor Green
        } else {
            Write-Host "⚠️  El archivo SQL se creó pero está vacío. Verifica las credenciales." -ForegroundColor Yellow
        }
    } catch {
        Write-Host "❌ Error al exportar base de datos: $_" -ForegroundColor Red
        Write-Host "   Puedes exportarla manualmente con:" -ForegroundColor Yellow
        Write-Host "   mysqldump -h $dbHost -u $dbUser -p $dbName > $sqlFile" -ForegroundColor Gray
    }
} else {
    Write-Host "⚠️  mysqldump no encontrado. Exporta la base de datos manualmente:" -ForegroundColor Yellow
    Write-Host "   mysqldump -h $dbHost -u $dbUser -p $dbName > $sqlFile" -ForegroundColor Gray
    Write-Host "`n   O desde phpMyAdmin: Exportar -> SQL" -ForegroundColor Gray
}

# ============================================
# RESUMEN
# ============================================
Write-Host "`n========================================" -ForegroundColor Cyan
Write-Host "  RESUMEN" -ForegroundColor Cyan
Write-Host "========================================`n" -ForegroundColor Cyan

Write-Host "✅ Archivos generados:" -ForegroundColor Green
if (Test-Path $backendZip) {
    $backendSize = (Get-Item $backendZip).Length / 1MB
    Write-Host "   • $backendZip ($([math]::Round($backendSize, 2)) MB)" -ForegroundColor White
}
if (Test-Path $frontendZip) {
    $frontendSize = (Get-Item $frontendZip).Length / 1MB
    Write-Host "   • $frontendZip ($([math]::Round($frontendSize, 2)) MB)" -ForegroundColor White
}
if (Test-Path $sqlFile) {
    $sqlSize = (Get-Item $sqlFile).Length / 1KB
    Write-Host "   • $sqlFile ($([math]::Round($sqlSize, 2)) KB)" -ForegroundColor White
}

Write-Host "`n✅ Proceso completado!" -ForegroundColor Green

