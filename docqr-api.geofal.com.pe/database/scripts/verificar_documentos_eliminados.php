<?php

/**
 * Script para verificar documentos eliminados (soft delete) que aún aparecen
 * 
 * USO: php database/scripts/verificar_documentos_eliminados.php
 */

require __DIR__ . '/../../vendor/autoload.php';

$app = require_once __DIR__ . '/../../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\QrFile;
use Illuminate\Support\Facades\DB;

echo "=== Verificación de Documentos Eliminados ===\n\n";

// Documentos activos (sin soft delete)
$activeCount = QrFile::withoutTrashed()->count();
echo "Documentos activos: {$activeCount}\n";

// Documentos eliminados (soft delete)
$deletedCount = QrFile::onlyTrashed()->count();
echo "Documentos eliminados (soft delete): {$deletedCount}\n";

// Total en BD (incluyendo eliminados)
$totalCount = QrFile::withTrashed()->count();
echo "Total en base de datos: {$totalCount}\n\n";

if ($deletedCount > 0) {
    echo "=== Documentos Eliminados (Soft Delete) ===\n";
    $deletedDocuments = QrFile::onlyTrashed()->get(['id', 'qr_id', 'folder_name', 'deleted_at']);
    
    foreach ($deletedDocuments as $doc) {
        echo sprintf(
            "ID: %d | QR_ID: %s | Carpeta: %s | Eliminado: %s\n",
            $doc->id,
            $doc->qr_id,
            $doc->folder_name,
            $doc->deleted_at?->format('Y-m-d H:i:s') ?? 'N/A'
        );
    }
    
    echo "\n";
    echo "¿Desea eliminar permanentemente estos documentos? (s/n): ";
    
    $handle = fopen("php://stdin", "r");
    $line = fgets($handle);
    $confirmation = trim(strtolower($line));
    fclose($handle);
    
    if ($confirmation === 's' || $confirmation === 'si' || $confirmation === 'y' || $confirmation === 'yes') {
        echo "\nEjecutando comando de eliminación permanente...\n";
        echo "Ejecuta: php artisan documents:force-delete-old --days=0\n";
        echo "O espera a que se ejecute automáticamente después de 30 días.\n";
    }
} else {
    echo "✅ No hay documentos eliminados (soft delete) en la base de datos.\n";
}

echo "\n=== Verificación de Consultas ===\n";
echo "Probando consulta sin withoutTrashed():\n";
$allCount = QrFile::count();
echo "QrFile::count() = {$allCount} (debería ser igual a activos: {$activeCount})\n";

if ($allCount !== $activeCount) {
    echo "⚠️  ADVERTENCIA: La consulta sin withoutTrashed() incluye documentos eliminados!\n";
    echo "Esto significa que hay un problema con el soft delete.\n";
} else {
    echo "✅ La consulta excluye correctamente los documentos eliminados.\n";
}

echo "\n";

