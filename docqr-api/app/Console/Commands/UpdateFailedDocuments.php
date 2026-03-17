<?php

namespace App\Console\Commands;

use App\Models\QrFile;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class UpdateFailedDocuments extends Command
{
    protected $signature = 'documents:update-failed 
                            {--path= : Ruta donde buscar archivos (ej: storage/app/document)}';

    protected $description = 'Buscar y actualizar documentos con status=failed después de subir archivos';

    public function handle()
    {
        $searchPath = $this->option('path') ?: storage_path('app/document');
        
        $this->info('🔍 Buscando documentos con status=failed...');
        
        $failedDocuments = QrFile::where('status', 'failed')->get();
        $total = $failedDocuments->count();
        
        if ($total === 0) {
            $this->info('✅ No hay documentos con status=failed');
            return 0;
        }
        
        $this->info("📊 Total de documentos con status=failed: {$total}");
        
        $bar = $this->output->createProgressBar($total);
        $bar->start();
        
        $updated = 0;
        $stillFailed = 0;
        
        foreach ($failedDocuments as $qrFile) {
            try {
                $filePath = $this->findFileForDocument($qrFile, $searchPath);
                
                if ($filePath) {
                    $qrFile->file_path = $filePath;
                    $qrFile->status = 'uploaded';
                    $qrFile->save();
                    $updated++;
                } else {
                    $stillFailed++;
                }
            } catch (\Exception $e) {
                Log::error("Error actualizando documento {$qrFile->id}: " . $e->getMessage());
                $stillFailed++;
            }
            
            $bar->advance();
        }
        
        $bar->finish();
        $this->newLine(2);
        
        $this->info("✅ Actualización completada:");
        $this->line("   ✓ Actualizados: {$updated}");
        $this->line("   ✗ Aún sin archivo: {$stillFailed}");
        
        return 0;
    }
    
    /**
     * Buscar archivo para un documento
     */
    private function findFileForDocument(QrFile $qrFile, string $searchPath): ?string
    {
        $fileName = $qrFile->original_filename;
        $cleanFileName = basename($fileName);
        $fileNameWithoutExt = pathinfo($cleanFileName, PATHINFO_FILENAME);
        
        // Extraer tipo y código del folder_name
        $folderName = $qrFile->folder_name;
        $parts = explode('-', $folderName, 2);
        $documentType = $parts[0] ?? null;
        $documentCode = $parts[1] ?? null;
        
        if (!is_dir($searchPath)) {
            return null;
        }
        
        // 1. Buscar en estructura: document/TIPO/YYYYMM/CODIGO/archivo.pdf
        if ($documentType && in_array($documentType, ['CE', 'IN', 'SU'])) {
            $typePath = $searchPath . '/' . $documentType;
            if (is_dir($typePath)) {
                // Buscar en todas las carpetas de fecha
                $allDateFolders = scandir($typePath);
                $dateFolders = array_filter($allDateFolders, function($folder) {
                    return $folder !== '.' && $folder !== '..' && 
                           (preg_match('/^\d{4}-?\d{2}$/', $folder) || preg_match('/^\d{6}$/', $folder));
                });
                
                foreach ($dateFolders as $dateFolder) {
                    $datePath = $typePath . '/' . $dateFolder;
                    if (is_dir($datePath)) {
                        // Buscar por código
                        if ($documentCode) {
                            $codePath = $datePath . '/' . $documentCode;
                            if (is_dir($codePath)) {
                                $pdfs = glob($codePath . '/*.pdf');
                                if (!empty($pdfs)) {
                                    return $this->getRelativePath($pdfs[0]);
                                }
                            }
                            
                            // Buscar por coincidencia parcial
                            $subdirs = glob($datePath . '/*', GLOB_ONLYDIR);
                            foreach ($subdirs as $subdir) {
                                $subdirName = basename($subdir);
                                $normalizedCode = preg_replace('/[^a-zA-Z0-9-]/', '', strtolower($documentCode));
                                $normalizedSubdir = preg_replace('/[^a-zA-Z0-9-]/', '', strtolower($subdirName));
                                
                                if (stripos($normalizedSubdir, $normalizedCode) !== false || 
                                    stripos($normalizedCode, $normalizedSubdir) !== false ||
                                    stripos($subdirName, $documentCode) !== false ||
                                    stripos($documentCode, $subdirName) !== false) {
                                    $pdfs = glob($subdir . '/*.pdf');
                                    if (!empty($pdfs)) {
                                        return $this->getRelativePath($pdfs[0]);
                                    }
                                }
                            }
                        }
                        
                        // Buscar directamente en la carpeta de fecha
                        $found = $this->searchFileRecursive($datePath, $cleanFileName);
                        if ($found) {
                            return $this->getRelativePath($found);
                        }
                        
                        $found = $this->searchFileRecursive($datePath, $fileNameWithoutExt, true);
                        if ($found) {
                            return $this->getRelativePath($found);
                        }
                    }
                }
            }
        }
        
        // 2. Buscar recursivamente en toda la carpeta
        $found = $this->searchFileRecursive($searchPath, $cleanFileName);
        if ($found) {
            return $this->getRelativePath($found);
        }
        
        $found = $this->searchFileRecursive($searchPath, $fileNameWithoutExt, true);
        if ($found) {
            return $this->getRelativePath($found);
        }
        
        return null;
    }
    
    /**
     * Buscar archivo recursivamente
     */
    private function searchFileRecursive(string $directory, string $searchTerm, bool $partial = false): ?string
    {
        if (!is_dir($directory)) {
            return null;
        }
        
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'pdf') {
                $fileName = $file->getFilename();
                
                if ($partial) {
                    if (stripos($fileName, $searchTerm) !== false) {
                        return $file->getPathname();
                    }
                } else {
                    if (strcasecmp($fileName, $searchTerm) === 0) {
                        return $file->getPathname();
                    }
                }
            }
        }
        
        return null;
    }
    
    /**
     * Convertir ruta absoluta a relativa
     */
    private function getRelativePath(string $absolutePath): string
    {
        $storagePath = storage_path('app/');
        
        if (strpos($absolutePath, $storagePath) === 0) {
            return str_replace($storagePath, '', $absolutePath);
        }
        
        if (preg_match('/document\/(.+)$/', $absolutePath, $matches)) {
            return 'document/' . $matches[1];
        }
        
        return basename($absolutePath);
    }
}