<?php

/**
 * Script para ejecutar la migración de documentos antiguos
 * 
 * USO:
 * php database/scripts/ejecutar_migracion.php
 */

// Obtener el directorio raíz del proyecto Laravel
// Este script está en: docqr-api/database/scripts/
// El raíz de Laravel está 2 niveles arriba (docqr-api/)
$rootDir = realpath(__DIR__ . '/../..');

if (!$rootDir) {
    // Si realpath falla, intentar con dirname
    $rootDir = dirname(__DIR__, 2);
}

// Verificar que existe vendor/autoload.php
$autoloadPath = $rootDir . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
if (!file_exists($autoloadPath)) {
    die("❌ ERROR: No se encontró vendor/autoload.php. Ejecuta 'composer install' primero.\n");
}

require $autoloadPath;

$bootstrapPath = $rootDir . DIRECTORY_SEPARATOR . 'bootstrap' . DIRECTORY_SEPARATOR . 'app.php';
if (!file_exists($bootstrapPath)) {
    die("❌ ERROR: No se encontró bootstrap/app.php.\n");
}

$app = require_once $bootstrapPath;
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

require __DIR__ . '/migrar_documentos_antiguos.php';

echo "\n🚀 Iniciando migración de documentos antiguos...\n\n";

migrarDocumentosAntiguos();

echo "\n✅ Proceso completado.\n\n";

