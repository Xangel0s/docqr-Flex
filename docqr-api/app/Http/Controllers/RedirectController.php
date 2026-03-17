<?php

namespace App\Http\Controllers;

use App\Models\Document;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;

/**
 * Controlador para redirigir URLs antiguas a nuevas
 * 
 * Este endpoint maneja redirecciones desde URLs antiguas del QR
 * hacia las nuevas URLs actualizadas en link_qr
 */
class RedirectController extends Controller
    {
        /**
         * Redirigir desde URL antigua a nueva URL
         * 
         * Maneja rutas como:
         * - /api/auth/login-download-file/IN-N-2272-25-SU20
         * - /api/auth/login-download-file/{codigo}
         * 
         * @param string $path Código del documento
         * @return RedirectResponse|\Illuminate\Http\Response
         */
        public function redirectOldQr(string $path)
        {
            try {
                // Log detallado para debugging del QR
                \Log::info('RedirectController - QR escaneado:', [
                    'path_recibido' => $path,
                    'full_url' => request()->fullUrl(),
                    'url' => request()->url(),
                    'method' => request()->method(),
                    'headers' => [
                        'host' => request()->header('Host'),
                        'user_agent' => request()->header('User-Agent'),
                        'referer' => request()->header('Referer'),
                    ],
                    'server_request_uri' => request()->server('REQUEST_URI'),
                ]);
                
                // Limpiar el código (quitar prefijos comunes)
                $codigo = str_replace('IN-', '', $path);
                $codigo = trim($codigo);
                
                // Normalizar espacios codificados en URL (+ se convierte en espacio)
                $codigo = str_replace('+', ' ', $codigo);
                $codigo = urldecode($codigo);
                
                // Normalizar espacios múltiples
                $codigo = preg_replace('/\s+/', ' ', $codigo);
                $codigo = trim($codigo);
                
                // Normalizar guiones múltiples (N--1155 -> N-1155)
                $codigo = preg_replace('/-+/', '-', $codigo);
                $codigo = trim($codigo, '-');
                
                // Normalizar el código para búsqueda (generar todas las variaciones posibles)
                $variaciones = $this->generarVariacionesCodigo($codigo);
                
                // Agregar variaciones con prefijo IN- para búsqueda en qr_files
                $variacionesConPrefijo = [];
                foreach ($variaciones as $variacion) {
                    $variacionesConPrefijo[] = 'IN-' . $variacion;
                    $variacionesConPrefijo[] = $variacion;
                }
                $variaciones = array_unique(array_merge($variaciones, $variacionesConPrefijo));
                
                // Buscar el documento por código (con todas las variantes)
                $document = Document::where(function($query) use ($codigo, $variaciones) {
                    // Búsqueda exacta primero
                    $query->where('code', $codigo);
                    
                    // Búsqueda con todas las variaciones generadas
                    foreach ($variaciones as $variacion) {
                        $query->orWhere('code', $variacion);
                    }
                    
                    // Búsqueda parcial como último recurso (solo si no hay resultados exactos)
                    $query->orWhere('code', 'like', "%{$codigo}%");
                })
                ->whereNull('deleted_at') // Solo documentos activos
                ->first();
                
                // Si no se encuentra en document, buscar en qr_files por folder_name
                if (!$document) {
                    \Log::info('Documento no encontrado en document, buscando en qr_files', [
                        'codigo_buscado' => $codigo,
                        'path_original' => $path
                    ]);
                    
                    // Buscar en qr_files con todas las variaciones (incluyendo IN-)
                    // Normalizar el código para búsqueda flexible
                    $codigoNormalizado = preg_replace('/-+/', '-', $codigo);
                    $codigoNormalizado = trim($codigoNormalizado, '-');
                    
                    // Extraer el código sin prefijo IN- para comparación
                    $codigoSinPrefijo = str_replace('IN-', '', $codigo);
                    $codigoSinPrefijo = preg_replace('/-+/', '-', $codigoSinPrefijo);
                    $codigoSinPrefijo = trim($codigoSinPrefijo, '-');
                    
                    $qrFile = \App\Models\QrFile::where(function($query) use ($codigo, $codigoNormalizado, $codigoSinPrefijo, $variaciones) {
                        // Búsqueda exacta primero
                        $query->where('folder_name', $codigo)
                              ->orWhere('folder_name', 'IN-' . $codigo)
                              ->orWhere('folder_name', $codigoNormalizado)
                              ->orWhere('folder_name', 'IN-' . $codigoNormalizado)
                              ->orWhere('folder_name', $codigoSinPrefijo)
                              ->orWhere('folder_name', 'IN-' . $codigoSinPrefijo);
                        
                        // Búsqueda con todas las variaciones
                        foreach ($variaciones as $variacion) {
                            // Normalizar variación también
                            $variacionNorm = preg_replace('/-+/', '-', $variacion);
                            $variacionNorm = trim($variacionNorm, '-');
                            $variacionSinPrefijo = str_replace('IN-', '', $variacionNorm);
                            $variacionSinPrefijo = trim($variacionSinPrefijo, '-');
                            
                            $query->orWhere('folder_name', 'like', "%{$variacion}%")
                                  ->orWhere('folder_name', 'like', "%{$variacionNorm}%")
                                  ->orWhere('folder_name', 'like', "%{$variacionSinPrefijo}%");
                        }
                        
                        // Búsqueda flexible normalizando folder_name también (maneja doble guión y prefijo IN-)
                        $query->orWhereRaw("REPLACE(REPLACE(REPLACE(folder_name, '--', '-'), 'IN-', ''), '---', '-') LIKE ?", ["%{$codigoSinPrefijo}%"]);
                        $query->orWhereRaw("REPLACE(REPLACE(REPLACE(folder_name, '--', '-'), 'IN-', ''), '---', '-') = ?", [$codigoSinPrefijo]);
                    })
                    ->whereNull('deleted_at')
                    ->first();
                    
                    if ($qrFile) {
                        \Log::info('qr_file encontrado, buscando document relacionado', [
                            'qr_id' => $qrFile->qr_id,
                            'folder_name' => $qrFile->folder_name,
                            'document_id' => $qrFile->document_id
                        ]);
                        
                        // Buscar document por document_id o por password_file
                        if ($qrFile->document_id) {
                            $document = Document::where('document_id', $qrFile->document_id)
                                ->whereNull('deleted_at')
                                ->first();
                        }
                        
                        // Si aún no se encuentra, buscar por password_file
                        if (!$document) {
                            $document = Document::where('password_file', $qrFile->qr_id)
                                ->whereNull('deleted_at')
                                ->first();
                        }
                        
                        // Si encontramos el qr_file pero no el document, usar el qr_id directamente
                        if (!$document && $qrFile->qr_id) {
                            \Log::info('Usando qr_file directamente para redirección (no document encontrado)', [
                                'qr_id' => $qrFile->qr_id,
                                'folder_name' => $qrFile->folder_name
                            ]);
                            $newUrl = url("/api/view/{$qrFile->qr_id}");
                            return redirect($newUrl, 301);
                        }
                        
                        // Si encontramos el qr_file Y el document, pero el password_file no coincide con qr_id,
                        // usar el qr_id del qr_file (más confiable)
                        if ($document && $qrFile->qr_id && $document->password_file !== $qrFile->qr_id) {
                            \Log::info('Usando qr_file directamente para redirección (password_file no coincide)', [
                                'qr_id' => $qrFile->qr_id,
                                'document_password_file' => $document->password_file,
                                'folder_name' => $qrFile->folder_name
                            ]);
                            $newUrl = url("/api/view/{$qrFile->qr_id}");
                            return redirect($newUrl, 301);
                        }
                    }
                }
                
                if (!$document) {
                    \Log::warning('Documento no encontrado para redirección', [
                        'codigo_buscado' => $codigo,
                        'path_original' => $path,
                        'variaciones_intentadas' => $variaciones
                    ]);
                    
                    // En lugar de abort(404), mostrar página HTML
                    $html = $this->generateErrorPage('Documento no encontrado', $codigo, 'N/A');
                    return response($html, 200)
                        ->header('Content-Type', 'text/html; charset=utf-8');
                }
                
                // SIEMPRE usar el endpoint /api/view/{password_file} porque las URLs directas no funcionan
                // Esto asegura que funcione incluso si link_qr tiene URLs incorrectas
                if ($document->password_file) {
                    $newUrl = url("/api/view/{$document->password_file}");
                    \Log::info('Redirección a endpoint API', [
                        'codigo' => $document->code,
                        'document_id' => $document->document_id,
                        'password_file' => $document->password_file,
                        'url_antigua' => request()->fullUrl(),
                        'url_nueva' => $newUrl,
                        'link_qr_actual' => $document->link_qr ?? 'N/A'
                    ]);
                    return redirect($newUrl, 301); // 301 = Redirección permanente
                }
                
                // Si no tiene password_file, mostrar página HTML en lugar de error
                \Log::warning('Documento sin password_file para redirección', [
                    'codigo' => $document->code ?? $codigo,
                    'document_id' => $document->document_id ?? 'N/A'
                ]);
                
                $html = $this->generateErrorPage(
                    'El documento no tiene configuración completa para visualización',
                    $document->code ?? $codigo,
                    'N/A'
                );
                return response($html, 200)
                    ->header('Content-Type', 'text/html; charset=utf-8');
                
            } catch (\Exception $e) {
                \Log::error('Error en redirección de QR antiguo: ' . $e->getMessage(), [
                    'path' => $path ?? 'N/A',
                    'trace' => $e->getTraceAsString(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ]);
                
                // En lugar de abort(500), mostrar página HTML
                $html = $this->generateErrorPage(
                    'Error al procesar la solicitud. Por favor, intente más tarde.',
                    $path ?? 'N/A',
                    'N/A'
                );
                return response($html, 200)
                    ->header('Content-Type', 'text/html; charset=utf-8');
            }
        }
        
        /**
         * Generar todas las variaciones posibles de un código
         * para mejorar la búsqueda de documentos
         * 
         * @param string $codigo Código original
         * @return array Array de variaciones
         */
        private function generarVariacionesCodigo(string $codigo): array
        {
            $variaciones = [];
            $codigo = trim($codigo);
            
            // Normalizar guiones múltiples primero (N--1155 -> N-1155)
            $codigo = preg_replace('/-+/', '-', $codigo);
            $codigo = trim($codigo, '-');
            
            // Agregar el código original
            $variaciones[] = $codigo;
            
            // Variaciones con espacios y guiones
            $variaciones[] = str_replace(' ', '-', $codigo);
            $variaciones[] = str_replace('-', ' ', $codigo);
            $variaciones[] = str_replace(' ', '', $codigo);
            
            // Variaciones con espacios en diferentes posiciones (ej: N-864-25-CO12 -> N-864-25 CO12)
            // Reemplazar el último guión antes del código por espacio
            if (preg_match('/^N[°\s\-]*(\d+)[\s\-]+(\d{2})[\s\-]+([A-Z0-9]+)$/i', $codigo, $matches)) {
                $variaciones[] = "N-{$matches[1]}-{$matches[2]} {$matches[3]}";
                $variaciones[] = "N°{$matches[1]}-{$matches[2]} {$matches[3]}";
                $variaciones[] = "N° {$matches[1]}-{$matches[2]} {$matches[3]}";
            }
            
            // Si el código ya tiene espacios (viene de URL codificada), generar variaciones sin espacios
            if (preg_match('/^N[°\s]*(\d+)[\s\-]+(\d{2})[\s]+([A-Z0-9]+)$/i', $codigo, $matches)) {
                // Ejemplo: N°864-25 CO12 -> N-864-25-CO12
                $variaciones[] = "N-{$matches[1]}-{$matches[2]}-{$matches[3]}";
                $variaciones[] = "N°{$matches[1]}-{$matches[2]}-{$matches[3]}";
                $variaciones[] = "N-{$matches[1]}-{$matches[2]}{$matches[3]}";
            }
            
            // Variaciones con espacios en diferentes posiciones
            // Ejemplo: N° 870-24 SU23, N° 870 24-SU23, N°-870-24-SU23
            if (preg_match('/^N[°\s\-]*(\d+)[\s\-]*(\d{2})[\s\-]*([A-Z0-9]+)$/i', $codigo, $matches)) {
                $num1 = $matches[1];
                $num2 = $matches[2];
                $sufijo = $matches[3];
                
                // Variaciones con espacios
                $variaciones[] = "N° {$num1}-{$num2} {$sufijo}";
                $variaciones[] = "N°{$num1}-{$num2} {$sufijo}";
                $variaciones[] = "N°-{$num1}-{$num2} {$sufijo}";
                $variaciones[] = "N° {$num1} {$num2}-{$sufijo}";
                $variaciones[] = "N°{$num1} {$num2}-{$sufijo}";
                $variaciones[] = "N°-{$num1} {$num2}-{$sufijo}";
                $variaciones[] = "N-{$num1} {$num2}-{$sufijo}";
                $variaciones[] = "N° {$num1} {$num2} {$sufijo}";
            }
            
            // Variaciones con N° y N-
            $variaciones[] = str_replace('N-', 'N° ', $codigo);
            $variaciones[] = str_replace('N° ', 'N-', $codigo);
            $variaciones[] = str_replace('N°', 'N-', $codigo);
            $variaciones[] = str_replace('N-', 'N°', $codigo);
            $variaciones[] = str_replace('°', '', $codigo);
            $variaciones[] = str_replace('N ', 'N-', $codigo);
            $variaciones[] = str_replace('N-', 'N ', $codigo);
            
            // Variaciones con espacios alrededor del símbolo °
            $variaciones[] = str_replace('N°', 'N° ', $codigo);
            $variaciones[] = str_replace('N° ', 'N°', $codigo);
            
            // Variaciones con doble guión (normalizar a guión simple)
            $variaciones[] = preg_replace('/-+/', '-', $codigo);
            $variaciones[] = preg_replace('/-+/', ' ', $codigo);
            
            // Variaciones con IN- prefijo (para búsqueda en qr_files)
            foreach ($variaciones as $variacion) {
                if (!str_starts_with($variacion, 'IN-')) {
                    $variaciones[] = 'IN-' . $variacion;
                }
            }
            
            // Eliminar duplicados y valores vacíos
            $variaciones = array_unique(array_filter($variaciones));
            
            return $variaciones;
        }
        
        /**
         * Generar página HTML de error simple
         * 
         * @param string $message Mensaje a mostrar
         * @param string $documentCode Código del documento
         * @param string $qrId ID del QR
         * @return string HTML
         */
        private function generateErrorPage(string $message, string $documentCode, string $qrId): string
        {
            return <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Documento no disponible</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
            background-color: #f5f5f5;
        }
        .container {
            text-align: center;
            padding: 40px;
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            max-width: 500px;
        }
        h1 {
            color: #333;
            margin-bottom: 20px;
        }
        p {
            color: #666;
            line-height: 1.6;
        }
        .code {
            background: #f0f0f0;
            padding: 10px;
            border-radius: 4px;
            margin: 20px 0;
            font-family: monospace;
            color: #333;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Documento no disponible</h1>
        <p>{$message}</p>
        <div class="code">
            <strong>Código:</strong> {$documentCode}<br>
            <strong>ID:</strong> {$qrId}
        </div>
        <p>Por favor, contacte al administrador si necesita este documento.</p>
    </div>
</body>
</html>
HTML;
        }
}