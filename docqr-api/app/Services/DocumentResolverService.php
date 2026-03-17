<?php

namespace App\Services;

use App\Models\Document;
use App\Models\QrFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class DocumentResolverService
{
    /**
     * Normaliza un código eliminando prefijos y limpiando espacios.
     * 
     * @param string $code
     * @return string
     */
    public function normalizeCode(string $code): string
    {
        $code = str_replace('IN-', '', $code);
        $code = trim($code);
        $code = str_replace('+', ' ', $code);
        $code = urldecode($code);
        $code = preg_replace('/\s+/', ' ', $code);
        $code = preg_replace('/-+/', '-', $code);
        return trim($code, '-');
    }

    /**
     * Genera todas las variaciones posibles de un código para mejorar la búsqueda.
     * 
     * @param string $code
     * @return array
     */
    public function getVariations(string $code): array
    {
        $variations = [];
        $code = $this->normalizeCode($code);
        
        if (empty($code)) return [];

        $variations[] = $code;
        
        // Variaciones básicas de espacios/guiones
        $variations[] = str_replace(' ', '-', $code);
        $variations[] = str_replace('-', ' ', $code);
        $variations[] = str_replace(' ', '', $code);
        
        // Variaciones con símbolos específicos (N° vs N-)
        $variations[] = str_replace('N-', 'N° ', $code);
        $variations[] = str_replace('N° ', 'N-', $code);
        $variations[] = str_replace('N°', 'N-', $code);
        $variations[] = str_replace('N-', 'N°', $code);
        $variations[] = str_replace('°', '', $code);
        $variations[] = str_replace('N ', 'N-', $code);
        
        // Patrones comunes: N-000-00 AREA
        if (preg_match('/^N[°\s\-]*(\d+)[\s\-]+(\d{2})[\s\-]+([A-Z0-9]+)$/i', $code, $matches)) {
            $variations[] = "N-{$matches[1]}-{$matches[2]} {$matches[3]}";
            $variations[] = "N°{$matches[1]}-{$matches[2]} {$matches[3]}";
            $variations[] = "N° {$matches[1]}-{$matches[2]} {$matches[3]}";
        }

        // Variaciones con prefijo IN- (para folder_name)
        $variationsWithPrefix = [];
        foreach ($variations as $v) {
            if (!str_starts_with($v, 'IN-')) {
                $variationsWithPrefix[] = 'IN-' . $v;
            }
        }
        
        return array_unique(array_merge($variations, $variationsWithPrefix));
    }

    /**
     * Busca un documento por código usando variaciones difusas.
     * 
     * @param string $code
     * @return \App\Models\Document|null
     */
    public function findDocument(string $code)
    {
        $variations = $this->getVariations($code);
        $normalized = $this->normalizeCode($code);

        // 1. Intentar búsqueda exacta en Document
        $document = Document::whereIn('code', $variations)
            ->whereNull('deleted_at')
            ->first();

        if ($document) return $document;

        // 2. Intentar búsqueda en QrFile (por folder_name)
        $qrFile = QrFile::whereIn('folder_name', $variations)
            ->whereNull('deleted_at')
            ->first();

        if ($qrFile) {
            if ($qrFile->document_id) {
                return Document::find($qrFile->document_id);
            }
            // Si no tiene document_id, buscar por password_file (si es un hash de QR)
            return Document::where('password_file', $qrFile->qr_id)->first();
        }

        // 3. Búsqueda parcial como último recurso
        return Document::where('code', 'like', "%{$normalized}%")
            ->whereNull('deleted_at')
            ->first();
    }

    /**
     * Resuelve la ruta física de un archivo PDF asociado a un documento.
     * 
     * @param Document $document
     * @return string|null
     */
    public function resolvePhysicalPath(Document $document): ?string
    {
        // Estrategia 1: file_path desde QrFile
        if ($document->password_file) {
            $qrFile = QrFile::where('qr_id', $document->password_file)->first();
            if ($qrFile && $qrFile->file_path) {
                $path = storage_path('app/' . $qrFile->file_path);
                if (file_exists($path)) return $path;
            }
        }

        // Estrategia 2: folder_name y file_name en Document
        if ($document->folder_name && $document->file_name) {
            $path = storage_path('app/uploads/document/' . $document->folder_name . '/' . $document->file_name);
            if (file_exists($path)) return $path;
        }

        // Estrategia 3: final_path
        if ($document->final_path) {
            $path = storage_path('app/' . ltrim($document->final_path, '/'));
            if (file_exists($path)) return $path;
            
            $path = Storage::disk('final')->path($document->final_path);
            if (file_exists($path)) return $path;
        }

        // Estrategia 4: Búsqueda recursiva por nombre
        if ($document->file_name) {
            $filename = basename($document->file_name);
            $uploadsDir = storage_path('app/uploads/document');
            
            if (is_dir($uploadsDir)) {
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($uploadsDir, \RecursiveDirectoryIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::SELF_FIRST
                );

                foreach ($iterator as $file) {
                    if ($file->isFile() && strcasecmp($file->getFilename(), $filename) === 0) {
                        return $file->getPathname();
                    }
                }
            }
        }

        return null;
    }
}
