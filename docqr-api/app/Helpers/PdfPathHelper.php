<?php

namespace App\Helpers;

use App\Models\QrFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

/**
 * Helper para determinar qué archivo PDF servir (priorización final_path vs file_path)
 * 
 * Lógica de priorización:
 * 1. Si existe final_path (PDF con QR embebido), usar ese
 * 2. Si no existe final_path pero existe file_path (PDF original), usar ese
 * 3. Si ninguno existe, buscar por folder_name o qr_id
 */
class PdfPathHelper
{
    /**
     * Obtener la ruta del PDF a servir (con priorización)
     * 
     * @param QrFile $qrFile Modelo QrFile
     * @return array|null Array con 'filePath', 'disk', 'fullPath' o null si no hay archivo
     */
    public static function getPdfPathToServe(QrFile $qrFile): ?array
    {
        // 1. Intentar con final_path
        if ($qrFile->final_path) {
            $disk = 'final';
            $fullPath = Storage::disk($disk)->path($qrFile->final_path);
            
            if (file_exists($fullPath)) {
                return [
                    'filePath' => $qrFile->final_path,
                    'disk' => $disk,
                    'fullPath' => $fullPath,
                    'type' => 'final'
                ];
            }
        }
        
        // 2. Intentar con file_path
        if ($qrFile->file_path) {
            $disk = 'local';
            $fullPath = Storage::disk($disk)->path($qrFile->file_path);
            
            if (file_exists($fullPath)) {
                return [
                    'filePath' => $qrFile->file_path,
                    'disk' => $disk,
                    'fullPath' => $fullPath,
                    'type' => 'original'
                ];
            }
            
            // Buscar rutas alternativas si file_path no coincide
            $alternativePath = self::findAlternativePath($qrFile);
            if ($alternativePath) return $alternativePath;
        }
        
        // 3. Buscar usando folder_name o qr_id como último recurso
        return self::findPathByMetadata($qrFile);
    }
    
    /**
     * Buscar archivo en rutas alternativas
     */
    private static function findAlternativePath(QrFile $qrFile): ?array
    {
        $possiblePaths = [];
        $filePath = $qrFile->file_path;
        
        if ($filePath) {
            $possiblePaths[] = storage_path('app/' . $filePath);
            
            if (str_contains($filePath, 'uploads/')) {
                $stripped = str_replace('uploads/', '', $filePath);
                $possiblePaths[] = storage_path('app/uploads/' . $stripped);
                $possiblePaths[] = public_path('uploads/' . $stripped);
            }
        }
        
        foreach ($possiblePaths as $path) {
            if (file_exists($path) && is_file($path)) {
                return [
                    'filePath' => str_replace(storage_path('app/'), '', $path),
                    'disk' => 'local',
                    'fullPath' => $path,
                    'type' => 'original'
                ];
            }
        }
        
        return null;
    }
    
    /**
     * Buscar archivo usando folder_name o qr_id en la estructura de uploads
     */
    private static function findPathByMetadata(QrFile $qrFile): ?array
    {
        $folderName = $qrFile->folder_name;
        $qrId = $qrFile->qr_id;
        
        if (!$folderName && !$qrId) return null;

        // Extraer tipo de documento (IN, CO, CE, SU)
        $tipoDoc = 'IN';
        if ($folderName && preg_match('/^(IN|CE|SU|CO)-/i', $folderName, $matches)) {
            $tipoDoc = strtoupper($matches[1]);
        }

        $uploadsDir = storage_path('app/uploads/document/' . $tipoDoc);
        if (!is_dir($uploadsDir)) {
            $uploadsDir = storage_path('app/uploads/' . $tipoDoc);
        }
        if (!is_dir($uploadsDir)) {
            $uploadsDir = storage_path('app/uploads');
        }

        if (!is_dir($uploadsDir)) return null;

        // Búsqueda recursiva limitada
        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($uploadsDir, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );
            $iterator->setMaxDepth(3); // Evitar escaneo infinito

            foreach ($iterator as $item) {
                if ($item->isDir()) {
                    $dirName = $item->getFilename();
                    
                    // Estrategia A: Carpeta con nombre de qr_id
                    if ($qrId && $dirName === $qrId) {
                        $pdf = self::findFirstPdf($item->getPathname());
                        if ($pdf) return self::formatFoundPath($pdf, $qrFile);
                    }
                    
                    // Estrategia B: Carpeta con nombre similar al folder_name
                    if ($folderName && self::isSimilarName($dirName, $folderName)) {
                        $pdf = self::findFirstPdf($item->getPathname());
                        if ($pdf) return self::formatFoundPath($pdf, $qrFile);
                    }
                }
            }
        } catch (\Exception $e) {
            Log::warning('Error en búsqueda de metadatos: ' . $e->getMessage());
        }

        return null;
    }

    private static function findFirstPdf($dir): ?string
    {
        $files = glob($dir . '/*.pdf');
        return !empty($files) ? $files[0] : null;
    }

    private static function formatFoundPath($fullPath, QrFile $qrFile): array
    {
        Log::info('PdfPathHelper: Archivo encontrado por metadatos', [
            'qr_id' => $qrFile->qr_id,
            'path' => $fullPath
        ]);
        
        return [
            'filePath' => str_replace(storage_path('app/'), '', $fullPath),
            'disk' => 'local',
            'fullPath' => $fullPath,
            'type' => 'original'
        ];
    }

    /**
     * Comparación robusta de nombres de carpetas
     */
    private static function isSimilarName($dirName, $folderName): bool
    {
        // Limpiar ambos nombres de caracteres no alfanuméricos para la comparación
        $cleanDir = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $dirName));
        $cleanFolder = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $folderName));
        
        if ($cleanDir === $cleanFolder) return true;
        
        // También intentar con el folderName recortado (sin prefijos)
        $shortFolder = preg_replace('/^(IN|CE|SU|CO)-N-/i', '', $folderName);
        $cleanShort = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $shortFolder));
        
        if (str_contains($cleanDir, $cleanShort) || str_contains($cleanShort, $cleanDir)) {
            return true;
        }

        // Estrategia Relaxed: Coincidir solo números (ej: 1069-25 vs 1069-25) ignorando prefijos/sufijos (SU06 vs SU20)
        // Extraer secuencia numérica principal de ambos
        $numsDir = [];
        $numsFolder = [];
        preg_match('/(\d+)[-](\d+)/', $folderName, $numsFolder);
        preg_match('/(\d+)[-](\d+)/', $dirName, $numsDir);

        if (!empty($numsFolder) && !empty($numsDir)) {
            // Comparar "1069-25" === "1069-25"
            $coreFolder = $numsFolder[1] . '-' . $numsFolder[2]; // 1069-25
            $coreDir = $numsDir[1] . '-' . $numsDir[2];       // 1069-25
            
            if ($coreFolder === $coreDir) {
                return true;
            }
        }

        return false;
    }
}