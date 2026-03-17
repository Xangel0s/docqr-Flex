<?php

namespace App\Http\Controllers;

use App\Models\QrFile;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

/**
 * Controlador para visualizar PDFs con QR
 * 
 * Esta ruta incrementa el contador de escaneos cada vez que se accede
 */
class ViewController extends Controller
{
    /**
     * Visualizar PDF final con QR (incrementa contador de escaneos)
     * 
     * @param string $hash QR ID del documento
     * @return Response
     */
    public function view(string $hash): Response
    {
        try {
            // Validar qr_id contra inyección SQL
            if (!\App\Helpers\QrIdValidator::isValid($hash)) {
                abort(400, 'ID de documento inválido');
            }
            
            // Buscar el documento por qr_id
            $qrFile = QrFile::where('qr_id', $hash)->first();

            if (!$qrFile) {
                abort(404, 'Documento no encontrado');
            }

            // Incrementar contador de escaneos SOLO cuando se accede al documento a través del QR
            // Esto valida que el documento fue escaneado correctamente
            $qrFile->incrementScanCount();

            // Determinar qué archivo servir usando helper compartido
            // Priorización: final_path > file_path > rutas alternativas
            $pdfInfo = \App\Helpers\PdfPathHelper::getPdfPathToServe($qrFile);
            
            if (!$pdfInfo) {
                Log::error('No se encontró archivo PDF para el documento:', [
                    'qr_id' => $hash,
                    'final_path' => $qrFile->final_path,
                    'file_path' => $qrFile->file_path
                ]);
                abort(404, 'Archivo PDF no encontrado');
            }
            
            $filePath = $pdfInfo['filePath'];
            $disk = $pdfInfo['disk'];
            $fullPath = $pdfInfo['fullPath'];
            $pdfType = $pdfInfo['type'];
            

            // Leer el contenido del archivo
            $content = file_get_contents($fullPath);

            // Retornar respuesta con headers apropiados para PDF
            // Headers de seguridad básicos (SecurityHeaders middleware maneja los demás)
            // Cache-Control: no-store para evitar problemas de caché en clientes
            $response = response($content, 200)
                ->header('Content-Type', 'application/pdf')
                ->header('Content-Disposition', 'inline; filename="' . $qrFile->original_filename . '"')
                ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
                ->header('Pragma', 'no-cache')
                ->header('Expires', '0')
                ->header('X-Content-Type-Options', 'nosniff');
            
            // Headers de seguridad para PDFs embebidos
            $frontendUrl = env('FRONTEND_URL', 'https://docqr.geofal.com.pe');
            $response->header('X-Frame-Options', 'SAMEORIGIN');
            $response->header('Content-Security-Policy', "frame-ancestors 'self' {$frontendUrl};");
            
            return $response;

        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Error al visualizar PDF: ' . $e->getMessage());
            abort(500, 'Error al cargar el documento');
        }
    }

    /**
     * Buscar documento por folder_name (código del documento) y redirigir a la vista del PDF
     * 
     * Esta ruta permite acceder a documentos usando el código/folder_name en lugar del qr_id hash.
     * Útil para URLs legacy como /doc/IN-N-804-25-CO12 o /auth/login-download-file/IN-N-804-25-CO12
     * 
     * @param string $folderName Código del documento (ej: IN-N-804-25-CO12)
     * @return \Illuminate\Http\RedirectResponse|Response
     */
    public function viewByFolderName(string $folderName)
    {
        try {
            $originalFolderName = urldecode($folderName);
            
            // Limpieza de caracteres de encoding (fix user request)
            $originalFolderName = str_replace(['Â', "\xc2\xa0", "\xa0"], ['', ' ', ' '], $originalFolderName);

            // Normalizar la entrada para búsqueda estándar
            $normalized = $this->normalizeDocumentCode($originalFolderName);
            
            Log::info('Buscando documento por folder_name:', [
                'original' => $originalFolderName,
                'normalized' => $normalized
            ]);

            // ========================================
            // ESTRATEGIA 1: Coincidencia exacta con QR válido y PDF Final
            // ========================================
            $qrFile = QrFile::where('folder_name', $normalized)
                ->whereNotNull('qr_id')
                ->whereNotNull('final_path')
                ->first();
            
            if ($qrFile) {
                Log::info('Documento encontrado: Coincidencia exacta + PDF Final', ['code' => $normalized, 'strategy' => 'exact_final']);
                return $this->performRedirection($qrFile);
            }

            // ========================================
            // ESTRATEGIA 2: Coincidencia exacta con QR válido y PDF Original
            // ========================================
            $qrFile = QrFile::where('folder_name', $normalized)
                ->whereNotNull('qr_id')
                ->whereNotNull('file_path')
                ->first();
            
            if ($qrFile) {
                Log::info('Documento encontrado: Coincidencia exacta + PDF Original', ['code' => $normalized, 'strategy' => 'exact_original']);
                return $this->performRedirection($qrFile);
            }

            // ========================================
            // ESTRATEGIA 3: Búsqueda con símbolo de grado (compatibilidad)
            // ========================================
            $folderNameWithDegree = str_replace('N-', 'N°-', $normalized);
            if ($folderNameWithDegree !== $normalized) {
                $qrFile = QrFile::where('folder_name', $folderNameWithDegree)
                    ->whereNotNull('qr_id')
                    ->first();
                
                if ($qrFile) {
                    Log::info('Documento encontrado: Con símbolo de grado', ['code' => $folderNameWithDegree, 'strategy' => 'degree_symbol']);
                    return $this->performRedirection($qrFile);
                }
            }

            // ========================================
            // ESTRATEGIA 4: Normalización agresiva (LIKE)
            // ========================================
            $aggressiveNormalized = $this->aggressiveNormalize($originalFolderName);
            if (!empty($aggressiveNormalized)) {
                $qrFile = QrFile::where('folder_name', 'LIKE', "%{$aggressiveNormalized}%")
                    ->whereNotNull('qr_id')
                    ->orderByRaw('LENGTH(folder_name) ASC') // Preferir el más corto (más específico)
                    ->first();
                
                if ($qrFile) {
                    Log::info('Documento encontrado: Normalización agresiva', ['code' => $aggressiveNormalized, 'strategy' => 'aggressive']);
                    return $this->performRedirection($qrFile);
                }
            }

            // Si llegamos aquí, no se encontró nada válido
            Log::warning('Documento no encontrado por ninguna estrategia:', [
                'original' => $originalFolderName,
                'normalized' => $normalized,
                'aggressive' => $aggressiveNormalized
            ]);
            
            return $this->renderNotFoundPage($originalFolderName, 'Documento no encontrado o no disponible');

        } catch (\Exception $e) {
            Log::error('Error al buscar documento por folder_name: ' . $e->getMessage());
            return $this->renderNotFoundPage($folderName, 'Error al procesar la solicitud');
        }
    }

    /**
     * Manejar URL legacy de uploads directos (ej: uploads/document/IN/202508/CODIGO/archivo.pdf)
     */
    public function handleLegacyUploadUrl($type, $date, $folderName, $filename)
    {
        Log::info('Interceptada URL legacy de upload:', [
            'type' => $type,
            'date' => $date,
            'folder' => $folderName,
            'file' => $filename
        ]);

        // Redirigir a la lógica de búsqueda por nombre de carpeta
        return $this->viewByFolderName($folderName);
    }

    /**
     * Realizar la redirección al visor de PDF
     */
    private function performRedirection(QrFile $qrFile)
    {
        // Incrementar contador de escaneos
        $qrFile->incrementScanCount();

        $apiUrl = env('API_URL', 'https://docqr-api.geofal.com.pe');
        return redirect("{$apiUrl}/api/view/{$qrFile->qr_id}");
    }

    /**
     * Normalizar el código del documento para búsquedas estándar
     */
    private function normalizeDocumentCode($code)
    {
        // Limpiar artefactos de encoding comunes (como Â en Â°)
        $code = str_replace(['Â', "\xc2\xa0", "\xa0"], ['', ' ', ' '], $code);
        
        // Eliminar duplicación de prefijos (ej: IN-IN-N-... -> IN-N-...)
        // Captura el grupo (IN-|CE-|SU-|CO-) y busca repeticiones inmediatas
        $code = preg_replace('/^((?:IN|CE|SU|CO)-)\1+/i', '$1', $code);

        // Eliminar variaciones comunes
        $normalized = strtoupper(trim($code));
        // Decodificar posibles entidades
        $normalized = urldecode($normalized);
        // Limpiar espacios múltiples y convertir a guiones
        $normalized = preg_replace('/\s+/', '-', $normalized);
        // Colapsar múltiples guiones o guiones bajos
        $normalized = preg_replace('/[-_]+/', '-', $normalized);
        
        return $normalized;
    }

    /**
     * Normalización agresiva para encontrar fragmentos significativos
     */
    private function aggressiveNormalize($code)
    {
        // 1. Eliminar prefijos comunes de búsqueda para encontrar la raíz (IN-, CE-, SU-, CO-)
        $clean = preg_replace('/^(IN|CE|SU|CO)-/i', '', strtoupper(trim($code)));
        
        // 2. Extraer partes numéricas principales (804-25 -> 804-25)
        if (preg_match('/(\d{1,4})[-\s]*(\d{1,4})/', $clean, $matches)) {
            return $matches[1] . '-' . $matches[2];
        }
        
        // 3. Fallback: limpiar todo excepto letras y números
        return preg_replace('/[^A-Z0-9]/i', '', $clean);
    }

    /**
     * Renderizar página HTML de "Documento no disponible"
     * 
     * @param string $codigo Código del documento buscado
     * @param string $mensaje Mensaje de error
     * @param string|null $qrId QR ID si se encontró el documento
     * @return Response
     */
    private function renderNotFoundPage(string $codigo, string $mensaje, ?string $qrId = null): Response
    {
        $html = <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Documento no disponible - DocQR</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background-color: #f3f4f6; 
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            padding: 40px;
            max-width: 500px;
            width: 100%;
            text-align: center;
        }
        .icon {
            font-size: 64px;
            margin-bottom: 20px;
        }
        h1 {
            color: #1f2937;
            font-size: 24px;
            margin-bottom: 12px;
        }
        .message {
            color: #6b7280;
            margin-bottom: 24px;
            font-size: 16px;
        }
        .info-box {
            background-color: #f9fafb;
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 24px;
            text-align: left;
        }
        .info-box p {
            color: #374151;
            margin: 8px 0;
            font-size: 14px;
        }
        .info-box strong {
            color: #1f2937;
        }
        .contact {
            color: #6b7280;
            font-size: 14px;
        }
        .btn {
            display: inline-block;
            background-color: #3b82f6;
            color: white;
            padding: 12px 24px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 500;
            margin-top: 16px;
            transition: background-color 0.2s;
        }
        .btn:hover {
            background-color: #2563eb;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="icon">📄</div>
        <h1>Documento no disponible</h1>
        <p class="message">{\$mensaje}</p>
        <div class="info-box">
            <p><strong>Código:</strong> {\$codigo}</p>
            <p><strong>ID:</strong> {\$qrId}</p>
        </div>
        <p class="contact">Por favor, contacte al administrador si necesita este documento.</p>
        <a href="https://docqr.geofal.com.pe" class="btn">Ir al inicio</a>
    </div>
</body>
</html>
HTML;

        return response($html, 404)
            ->header('Content-Type', 'text/html; charset=UTF-8');
    }
}