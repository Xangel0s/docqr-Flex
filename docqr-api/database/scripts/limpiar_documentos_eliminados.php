<?php

/**
 * Script para eliminar permanentemente documentos que fueron eliminados con soft delete
 * 
 * USO: php database/scripts/limpiar_documentos_eliminados.php
 * 
 * Este script elimina permanentemente (forceDelete) todos los documentos
 * que tienen deleted_at != null (soft deleted)
 */

require __DIR__ . '/../../vendor/autoload.php';

$app = require_once __DIR__ . '/../../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\QrFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;

echo "=== Limpieza de Documentos Eliminados ===\n\n";

// Obtener todos los documentos eliminados (soft delete)
$deletedDocuments = QrFile::onlyTrashed()->get();

if ($deletedDocuments->isEmpty()) {
    echo "No hay documentos eliminados para limpiar.\n";
    exit(0);
}

echo "Encontrados {$deletedDocuments->count()} documentos eliminados.\n";
echo "¿Desea eliminarlos permanentemente? (s/n): ";

$handle = fopen("php://stdin", "r");
$line = fgets($handle);
$confirmation = trim(strtolower($line));
fclose($handle);

if ($confirmation !== 's' && $confirmation !== 'si' && $confirmation !== 'y' && $confirmation !== 'yes') {
    echo "Operación cancelada.\n";
    exit(0);
}

$deletedCount = 0;
$errorCount = 0;

foreach ($deletedDocuments as $document) {
    try {
        DB::transaction(function () use ($document, &$deletedCount) {
            // Eliminar archivos físicos
            if ($document->file_path) {
                try {
                    if (Storage::disk('local')->exists($document->file_path)) {
                        Storage::disk('local')->delete($document->file_path);
                    }
                } catch (\Exception $e) {
                    echo "  ⚠️  Error al eliminar archivo PDF: {$e->getMessage()}\n";
                }
            }
            
            if ($document->qr_path) {
                try {
                    $qrFilename = basename($document->qr_path);
                    if (Storage::disk('qrcodes')->exists($qrFilename)) {
                        Storage::disk('qrcodes')->delete($qrFilename);
                    }
                } catch (\Exception $e) {
                    echo "  ⚠️  Error al eliminar QR: {$e->getMessage()}\n";
                }
            }
            
            if ($document->final_path) {
                try {
                    $finalPath = str_replace('final/', '', $document->final_path);
                    if (Storage::disk('final')->exists($finalPath)) {
                        Storage::disk('final')->delete($finalPath);
                    }
                } catch (\Exception $e) {
                    echo "  ⚠️  Error al eliminar PDF final: {$e->getMessage()}\n";
                }
            }
            
            // Eliminar permanentemente de la base de datos
            $document->forceDelete();
            $deletedCount++;
            echo "  ✅ Eliminado: {$document->qr_id} ({$document->folder_name})\n";
        });
    } catch (\Exception $e) {
        $errorCount++;
        echo "  ❌ Error al eliminar {$document->qr_id}: {$e->getMessage()}\n";
    }
}

echo "\n=== Resumen ===\n";
echo "Documentos eliminados permanentemente: {$deletedCount}\n";
echo "Errores: {$errorCount}\n";
echo "\n¡Limpieza completada!\n";

