<?php

use Illuminate\Support\Facades\Route;
use App\Models\Document;

// Ruta temporal de diagnóstico - ELIMINAR después de resolver el problema
Route::get('/diagnostico-redirect/{path}', function (string $path) {
    try {
        // Limpiar el código
        $codigo = str_replace('IN-', '', $path);
        $codigo = trim($codigo);
        
        // Buscar el documento
        $document = Document::where(function($query) use ($codigo) {
            $query->where('code', $codigo)
                ->orWhere('code', str_replace(' ', '-', $codigo))
                ->orWhere('code', str_replace('-', ' ', $codigo))
                ->orWhere('code', str_replace('°', '', $codigo))
                ->orWhere('code', str_replace('N-', 'N° ', $codigo))
                ->orWhere('code', str_replace('N° ', 'N-', $codigo))
                ->orWhere('code', 'like', "%{$codigo}%");
        })->first();
        
        $resultado = [
            'path_recibido' => $path,
            'codigo_limpio' => $codigo,
            'documento_encontrado' => $document ? true : false,
        ];
        
        if ($document) {
            $resultado['documento'] = [
                'document_id' => $document->document_id,
                'code' => $document->code,
                'password_file' => $document->password_file,
                'link_qr' => $document->link_qr,
                'folder_name' => $document->folder_name,
                'file_name' => $document->file_name,
                'final_path' => $document->final_path,
            ];
            
            // Verificar si el archivo existe
            $filePath = null;
            if ($document->folder_name && $document->file_name) {
                $storagePath = storage_path('app/uploads/document/' . $document->folder_name . '/' . $document->file_name);
                $resultado['ruta_intentada'] = $storagePath;
                $resultado['archivo_existe'] = file_exists($storagePath);
            }
            
            if ($document->final_path) {
                $finalPath = storage_path('app/' . ltrim($document->final_path, '/'));
                $resultado['final_path_completo'] = $finalPath;
                $resultado['final_path_existe'] = file_exists($finalPath);
            }
            
            // URL que se generaría
            $resultado['url_redireccion'] = url("/api/view/{$document->password_file}");
        } else {
            // Buscar variantes para debugging
            $variantes = [
                $codigo,
                str_replace(' ', '-', $codigo),
                str_replace('-', ' ', $codigo),
                str_replace('°', '', $codigo),
                str_replace('N-', 'N° ', $codigo),
                str_replace('N° ', 'N-', $codigo),
            ];
            
            $resultado['variantes_buscadas'] = $variantes;
            $resultado['documentos_similares'] = Document::where('code', 'like', "%{$codigo}%")
                ->limit(5)
                ->get(['document_id', 'code', 'password_file'])
                ->toArray();
        }
        
        return response()->json($resultado, 200);
        
    } catch (\Exception $e) {
        return response()->json([
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ], 500);
    }
})->where('path', '.*');

