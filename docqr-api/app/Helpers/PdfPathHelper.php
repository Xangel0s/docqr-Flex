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
 * 3. Si ninguno existe, retornar null
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
        // PRIORIDAD 1: PDF final con QR embebido
        if ($qrFile->final_path) {
            // PDF final: final/{TIPO}/{YYYYMM}/{qr_id}/documento.pdf
            // Remover prefijo 'final/' para obtener ruta relativa al disco 'final'
            $filePath = str_replace('final/', '', $qrFile->final_path);
            $disk = 'final';
            $fullPath = Storage::disk($disk)->path($filePath);
            
            // Verificar que el archivo existe físicamente
            if (file_exists($fullPath)) {
                return [
                    'filePath' => $filePath,
                    'disk' => $disk,
                    'fullPath' => $fullPath,
                    'type' => 'final' // PDF con QR embebido
                ];
            } else {
                // El archivo no existe físicamente, intentar con file_path como fallback
                Log::warning('PDF final no encontrado físicamente, usando file_path como fallback:', [
                    'qr_id' => $qrFile->qr_id,
                    'final_path' => $qrFile->final_path,
                    'expected_path' => $fullPath
                ]);
            }
        }
        
        // PRIORIDAD 2: PDF original (sin QR embebido)
        if ($qrFile->file_path) {
            $filePath = $qrFile->file_path;
            $disk = 'local';
            $fullPath = Storage::disk($disk)->path($filePath);
            
            // Verificar que el archivo existe físicamente
            if (file_exists($fullPath)) {
                return [
                    'filePath' => $filePath,
                    'disk' => $disk,
                    'fullPath' => $fullPath,
                    'type' => 'original' // PDF original sin QR
                ];
            } else {
                // El archivo no existe físicamente, intentar buscar en rutas alternativas
                Log::warning('PDF original no encontrado físicamente, buscando en rutas alternativas:', [
                    'qr_id' => $qrFile->qr_id,
                    'file_path' => $qrFile->file_path,
                    'expected_path' => $fullPath
                ]);
                
                // Buscar en rutas alternativas (compatibilidad con estructuras antiguas)
                $alternativePath = self::findAlternativePath($qrFile);
                if ($alternativePath) {
                    return $alternativePath;
                }
            }
        }
        
        // No se encontró ningún archivo
        return null;
    }
    
    /**
     * Buscar archivo en rutas alternativas (compatibilidad con estructuras antiguas)
     * 
     * @param QrFile $qrFile
     * @return array|null
     */
    private static function findAlternativePath(QrFile $qrFile): ?array
    {
        $possiblePaths = [];
        
        // Agregar rutas posibles basadas en file_path
        if ($qrFile->file_path) {
            $possiblePaths[] = storage_path('app/' . $qrFile->file_path);
            $possiblePaths[] = base_path('storage/app/' . $qrFile->file_path);
            
            // Si file_path contiene "uploads/", buscar también sin el prefijo
            if (str_contains($qrFile->file_path, 'uploads/')) {
                $pathWithoutUploads = str_replace('uploads/', '', $qrFile->file_path);
                $possiblePaths[] = storage_path('app/uploads/' . $pathWithoutUploads);
                $possiblePaths[] = base_path('storage/app/uploads/' . $pathWithoutUploads);
                $possiblePaths[] = public_path('uploads/' . $pathWithoutUploads);
            }
        }
        
        // Buscar por nombre de archivo si tenemos original_filename
        if ($qrFile->original_filename) {
            $filename = basename($qrFile->original_filename);
            $possiblePaths[] = storage_path('app/uploads/' . $filename);
            $possiblePaths[] = base_path('storage/app/uploads/' . $filename);
            $possiblePaths[] = public_path('uploads/' . $filename);
            
            // Buscar recursivamente en uploads
            $uploadsDir = storage_path('app/uploads');
            if (is_dir($uploadsDir)) {
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($uploadsDir, \RecursiveDirectoryIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::SELF_FIRST
                );
                foreach ($iterator as $file) {
                    if ($file->isFile() && $file->getFilename() === $filename) {
                        $possiblePaths[] = $file->getPathname();
                        break;
                    }
                }
            }
        }
        
        // Buscar el primer archivo que exista
        foreach ($possiblePaths as $possiblePath) {
            if ($possiblePath && file_exists($possiblePath) && is_file($possiblePath)) {
                Log::info('PDF encontrado en ruta alternativa:', [
                    'qr_id' => $qrFile->qr_id,
                    'ruta_original' => $qrFile->file_path,
                    'ruta_encontrada' => $possiblePath
                ]);
                
                // Determinar el disco basándose en la ruta
                $disk = 'local';
                if (str_contains($possiblePath, 'final/')) {
                    $disk = 'final';
                }
                
                return [
                    'filePath' => str_replace([storage_path('app/'), base_path('storage/app/')], '', $possiblePath),
                    'disk' => $disk,
                    'fullPath' => $possiblePath,
                    'type' => 'original' // Asumimos que es original si está en ruta alternativa
                ];
            }
        }
        
        return null;
    }
}

