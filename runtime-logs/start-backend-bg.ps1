$ErrorActionPreference = 'Stop'

$projectRoot = 'c:\Users\Lenovo\Documents\GEOFALQR'
$workingDir = Join-Path $projectRoot 'docqr-api'
$logFile = Join-Path $projectRoot 'runtime-logs\backend.log'
$errorFile = Join-Path $projectRoot 'runtime-logs\backend.err.log'
$php = 'C:\xampp\php\php.exe'

Set-Location $workingDir

if (Test-Path $logFile) {
    Remove-Item $logFile -Force
}

if (Test-Path $errorFile) {
    Remove-Item $errorFile -Force
}

& $php artisan serve --host=127.0.0.1 --port=8000 1>> $logFile 2>> $errorFile
