<?php

namespace App\Services;

use setasign\Fpdi\Tcpdf\Fpdi;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Smalot\PdfParser\Parser;

/**
 * Servicio para procesar PDFs y embebir códigos QR
 * 
 * Usa FPDI y TCPDF para manipular PDFs y agregar códigos QR
 */
class PdfProcessorService
{
    /**
     * Instancia de parser de PDF para análisis de contenido
     */
    private $parser;

    public function __construct()
    {
        $this->parser = new Parser();
    }

    /**
     * Embebir código QR en un PDF
     * 
     * @param string $pdfPath Ruta del PDF (puede ser uploads/... o final/...)
     * @param string $qrPath Ruta de la imagen QR (storage/qrcodes/...)
     * @param array $position Posición del QR: ['x' => int, 'y' => int, 'width' => int, 'height' => int]
     * @param string|null $pdfDisk Disco donde está el PDF ('local' o 'final')
     * @param string|null $qrId ID del QR (opcional, para nueva estructura optimizada de carpetas)
     * @return string Ruta relativa del PDF final (storage/final/...)
     * @throws \Exception Si hay error al procesar el PDF
     */
    public function embedQr(string $pdfPath, string $qrPath, array $position, ?string $pdfDisk = 'local', ?string $qrId = null, ?string $documentTitle = null, ?string $folderName = null, ?int $pageNumber = 1): string
    {
        try {
            // Aumentar límites para PDFs grandes (hasta 500MB)
            ini_set('memory_limit', '1024M'); // 1GB para PDFs muy grandes
            set_time_limit(600); // 10 minutos para PDFs grandes
            
            // Crear instancia de FPDI
            $pdf = new Fpdi();
            
            // Establecer metadatos del PDF para que el visor muestre el título correcto
            if ($documentTitle) {
                $pdf->SetTitle($documentTitle);
            } elseif ($folderName) {
                $pdf->SetTitle($folderName);
            }
            $pdf->SetAuthor('Geofal');
            $pdf->SetSubject('Documento con código QR');
            $pdf->SetCreator('Geofal - Sistema de Gestión de Documentos');
            
            // Obtener rutas completas según el disco
            if ($pdfDisk === 'final') {
                $filePath = str_replace('final/', '', $pdfPath);
                $fullPdfPath = Storage::disk('final')->path($filePath);
            } else {
                $fullPdfPath = Storage::disk('local')->path($pdfPath);
            }
            
            $fullQrPath = Storage::disk('qrcodes')->path(basename($qrPath));

            // Verificar que los archivos existan
            if (!file_exists($fullPdfPath)) {
                throw new \Exception("El archivo PDF no existe: {$fullPdfPath}");
            }
            
            if (!file_exists($fullQrPath)) {
                throw new \Exception("El archivo QR no existe: {$fullQrPath}");
            }

            // Si el PDF está protegido con contraseña, FPDI lanzará una excepción
            try {
                $pageCount = $pdf->setSourceFile($fullPdfPath);
            } catch (\setasign\Fpdi\PdfParser\CrossReference\CrossReferenceException $e) {
                $errorMsg = $e->getMessage();
                if (stripos($errorMsg, 'password') !== false || 
                    stripos($errorMsg, 'encrypted') !== false ||
                    stripos($errorMsg, 'protected') !== false) {
                    throw new \Exception("El PDF está protegido con contraseña. No se puede procesar automáticamente. Por favor, desbloquee el PDF antes de subirlo.");
                }
                throw $e;
            }
            
            if ($pageCount === 0) {
                throw new \Exception("El PDF no tiene páginas válidas");
            }

            // --- LÓGICA INTELIGENTE DE PÁGINA ---
            // Si pageNumber es 0 o null, aplicar lógica:
            // - Múltiples páginas o 1 página: siempre en la última (que es la 1 si solo hay una)
            if ($pageNumber === 0 || $pageNumber === null) {
                $pageNumber = $pageCount;
                Log::info("Selección automática de página inteligente: Inyectando en la última página ({$pageNumber}) de {$pageCount}.");
            }

            // Validar que el número de página sea válido
            if ($pageNumber < 1 || $pageNumber > $pageCount) {
                throw new \Exception("Página {$pageNumber} no existe. El PDF tiene {$pageCount} página(s).");
            }

            // Importar todas las páginas del PDF
            $pageTemplates = [];
            $pageSizes = [];
            for ($i = 1; $i <= $pageCount; $i++) {
                $tplId = $pdf->importPage($i);
                $pageTemplates[$i] = $tplId;
                $pageSizes[$i] = $pdf->getTemplateSize($tplId);
            }
            
            // Obtener dimensiones de la página donde se colocará el QR
            $targetPageSize = $pageSizes[$pageNumber];
            $pageWidth = $targetPageSize['width'];
            $pageHeight = $targetPageSize['height'];

            // Determinar si el PDF está en mm o en puntos
            $isInMm = ($pageWidth < 1000 && $pageHeight < 1000);
            
            // LÓGICA DE TAMAÑO INTELIGENTE:
            // Si el ancho es 0 o muy pequeño, o si estamos en auto-detección (pageNumber 0),
            // podemos asegurar un tamaño 'profesional' (aprox 42-45 puntos = ~1.5cm)
            $targetWidth = (double)($position['width'] ?? 0);
            $targetHeight = (double)($position['height'] ?? 0);
            
            if ($targetWidth < 10) {
                $targetWidth = 42.0; // Tamaño profesional estándar
                $targetHeight = 42.0;
            }

            // MÉTODO MEJORADO: Usar porcentajes relativos para conversión exacta (espacio estándar 595x842)
            $xPercent = (double)($position['x'] ?? 0) / 595.0;
            $yPercent = (double)($position['y'] ?? 0) / 842.0;
            $widthPercent = $targetWidth / 595.0;
            $heightPercent = $targetHeight / 842.0;
            
            // Aplicar porcentajes a las dimensiones reales del PDF
            $x = $xPercent * (double)$pageWidth;
            $y = $yPercent * (double)$pageHeight;
            $width = $widthPercent * (double)$pageWidth;
            $height = $heightPercent * (double)$pageHeight;

            // --- LÓGICA DE INYECCIÓN INTELIGENTE (MICROSERVICIO PYTHON) ---
            Log::info("=== INICIO ALGORITMO INTELIGENTE (PyMuPDF) ===", [
                'qr_input_position' => ['x' => $x, 'y' => $y, 'w' => $width, 'h' => $height],
                'page_dimensions' => ['w' => $pageWidth, 'h' => $pageHeight],
                'page_number' => $pageNumber
            ]);
            
            try {
                $placementInfo = $this->findSafePositionPython($fullPdfPath, $pageNumber, $x, $y, $width, $height, $pageWidth, $pageHeight);
                
                if ($placementInfo && isset($placementInfo['x'])) {
                    Log::info("=== RESULTADO ALGORITMO INTELIGENTE (PyMuPDF) ===", [
                        'posicion_original' => ['x' => $x, 'y' => $y, 'w' => $width, 'h' => $height],
                        'posicion_ajustada' => $placementInfo,
                        'adjusted' => $placementInfo['adjusted'] ?? false,
                        'reason' => $placementInfo['reason'] ?? 'N/A'
                    ]);
                    
                    // Aplicar ajustes de posición X e Y
                    if (abs($placementInfo['x'] - $x) > 0.1) {
                        Log::info("Ajuste inteligente de posición X: cambió de {$x} a {$placementInfo['x']}");
                        $x = $placementInfo['x'];
                    }
                    
                    if (abs($placementInfo['y'] - $y) > 0.1) {
                        Log::info("Ajuste inteligente de posición Y: cambió de {$y} a {$placementInfo['y']}");
                        $y = $placementInfo['y'];
                    }

                    if (abs($placementInfo['width'] - $width) > 0.1) {
                        Log::info("Ajuste inteligente de tamaño: {$width}x{$height} → {$placementInfo['width']}x{$placementInfo['height']}");
                        $width = $placementInfo['width'];
                        $height = $placementInfo['height'];
                    }
                } else {
                    Log::warning("Microservicio Python no devolvió resultado válido. Usando posición original.");
                }
            } catch (\Exception $contentError) {
                Log::warning("No se pudo contactar microservicio Python: " . $contentError->getMessage());
                Log::info("Usando fallback PHP para posicionamiento.");
                // Fallback al método PHP original
                try {
                    $placementInfo = $this->findSafePosition($fullPdfPath, $pageNumber, $x, $y, $width, $height, $pageWidth, $pageHeight);
                    if ($placementInfo['y'] !== $y) { $y = $placementInfo['y']; }
                    if ($placementInfo['x'] !== $x) { $x = $placementInfo['x']; }
                    if ($placementInfo['width'] !== $width) { $width = $placementInfo['width']; $height = $placementInfo['height']; }
                } catch (\Exception $fallbackError) {
                    Log::warning("Fallback PHP también falló: " . $fallbackError->getMessage());
                }
            }
            
            // Redondear solo al final
            if ($isInMm) {
                $x = round($x, 6); $y = round($y, 6); $width = round($width, 6); $height = round($height, 6);
            } else {
                $x = round($x, 2); $y = round($y, 2); $width = round($width, 2); $height = round($height, 2);
            }

            $SAFE_MARGIN_STANDARD = 0;
            $safeMarginX = ($SAFE_MARGIN_STANDARD * $pageWidth) / 595.0;
            $safeMarginY = ($SAFE_MARGIN_STANDARD * $pageHeight) / 842.0;
            $tolerance = 0.1;
            
            $qrBottom = $y + $height;
            $qrRight = $x + $width;
            
            if ($x < $safeMarginX - $tolerance || $y < $safeMarginY - $tolerance || 
                $qrRight > $pageWidth - $safeMarginX + $tolerance || 
                $qrBottom > $pageHeight - $safeMarginY + $tolerance) {
                if ($x < -$tolerance || $y < -$tolerance || 
                    $qrRight > $pageWidth + $tolerance || 
                    $qrBottom > $pageHeight + $tolerance) {
                    throw new \Exception("El QR está fuera del área del PDF. Ajusta la posición en el editor.");
                }
            }

            $pdf->SetAutoPageBreak(false, 0);
            
            // Agregar TODAS las páginas del PDF original
            for ($i = 1; $i <= $pageCount; $i++) {
                $currentPageSize = $pageSizes[$i];
                $pdf->AddPage($currentPageSize['orientation'], [$currentPageSize['width'], $currentPageSize['height']]);
                $pdf->useTemplate($pageTemplates[$i], 0, 0, $currentPageSize['width'], $currentPageSize['height'], true);
            }
            
            // Cambiar a la página donde se colocará el QR
            if ($pageCount === 1) {
                if ($pdf->getPage() !== 1) $pdf->setPage(1);
            } else {
                $pdf->setPage($pageNumber);
            }

            $pdfY = $y;
            $finalWidth = $width;
            $finalHeight = $width; // Forzar cuadrado
            
            // Asegurar que estamos en la página correcta antes de insertar
            if ($pdf->getPage() !== $pageNumber) {
                $pdf->setPage($pageNumber);
            }
            
            $pdf->Image($fullQrPath, $x, $pdfY, $finalWidth, $finalHeight);
            
            // Estructura de carpetas: final/{TIPO}/{YYYYMM}/{qr_id}/documento.pdf
            $pathParts = explode('/', $pdfPath);
            $documentType = 'OTROS';
            $monthYear = now()->format('Ym');
            $qrIdFromPath = $qrId ?? 'legacy-' . time();
            
            if (count($pathParts) >= 4 && in_array(strtoupper($pathParts[1] ?? ''), ['CE', 'IN', 'SU'])) {
                $documentType = strtoupper($pathParts[1]);
                if (preg_match('/^(\d{6})$/', $pathParts[2], $matches)) {
                    $monthYear = $matches[1];
                    $qrIdFromPath = $pathParts[3] ?? $qrIdFromPath;
                }
            }
            
            $originalBasename = basename($pdfPath);
            $finalFilename = preg_replace('/^\d{6}-[a-zA-Z0-9]{32}-/', '', $originalBasename);
            
            $finalFolder = "{$documentType}/{$monthYear}/{$qrIdFromPath}";
            Storage::disk('final')->makeDirectory($finalFolder);
            $finalPath = "{$finalFolder}/{$finalFilename}";
            $fullFinalPath = Storage::disk('final')->path($finalPath);

            // Guardar el PDF final
            $pdf->Output($fullFinalPath, 'F');
            
            return $finalPath;

        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();
            Log::error('Error al procesar PDF: ' . $errorMessage, [
                'pdf_path' => $pdfPath,
                'qr_path' => $qrPath,
                'position' => $position,
                'trace' => $e->getTraceAsString()
            ]);
            
            if (preg_match('/compression|not supported|FPDI/i', $errorMessage)) {
                throw new \Exception($errorMessage);
            }
            throw new \Exception("Error al embebir QR en PDF: " . $errorMessage);
        }
    }

    /**
     * Llama al microservicio Python (PyMuPDF) para encontrar posición segura del QR.
     * Detecta texto, imágenes, gráficos con coordenadas exactas.
     */
    private function findSafePositionPython(string $pdfPath, int $pageNumber, float $x, float $y, float $w, float $h, float $pageWidth, float $pageHeight): ?array
    {
        $analyzerUrl = env('PDF_ANALYZER_URL', 'http://127.0.0.1:8001');
        $timeout = 10; // segundos

        Log::info(">>> Llamando microservicio Python en {$analyzerUrl}/find-safe-position");

        $ch = curl_init();
        
        $postFields = [
            'file' => new \CURLFile($pdfPath, 'application/pdf', basename($pdfPath)),
            'x' => (string)$x,
            'y' => (string)$y,
            'width' => (string)$w,
            'height' => (string)$h,
            'page_number' => (string)$pageNumber,
            'page_width' => (string)$pageWidth,
            'page_height' => (string)$pageHeight
        ];

        curl_setopt_array($ch, [
            CURLOPT_URL => "{$analyzerUrl}/find-safe-position",
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postFields,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 3
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            Log::error("Error de conexión con microservicio Python: {$error}");
            throw new \Exception("Microservicio Python no disponible: {$error}");
        }

        if ($httpCode !== 200) {
            Log::error("Microservicio Python respondió con HTTP {$httpCode}: {$response}");
            throw new \Exception("Microservicio Python respondió con error HTTP {$httpCode}");
        }

        $data = json_decode($response, true);
        
        if (!$data || !isset($data['success']) || !$data['success']) {
            $errorMsg = $data['error'] ?? 'Respuesta inválida';
            Log::error("Microservicio Python retornó error: {$errorMsg}");
            throw new \Exception("Error del microservicio: {$errorMsg}");
        }

        Log::info(">>> Respuesta del microservicio Python", $data['data'] ?? []);
        return $data['data'] ?? null;
    }

    /**
     * Busca una posición segura (sin solapamiento) para el QR
     * 
     * @param string $fullPath Ruta completa del PDF
     * @param int $pageNumber Número de página (1-based)
     * @param float $x X objetivo
     * @param float $y Y objetivo
     * @param float $w Ancho del QR
     * @param float $h Alto del QR
     * @param float $pageWidth Ancho total de la página
     * @param float $pageHeight Alto total de la página
     * @return array Array con posición ajustada: ['x' => float, 'y' => float, 'width' => float, 'height' => float]
     */
    private function findSafePosition(string $fullPath, int $pageNumber, float $x, float $y, float $w, float $h, float $pageWidth, float $pageHeight): array
    {
        // Inicializar resultado con valores originales
        $result = [
            'x' => $x,
            'y' => $y,
            'width' => $w,
            'height' => $h
        ];
        
        try {
            Log::info("Analizando posición segura para QR en página {$pageNumber}", [
                'target_x' => $x, 'target_y' => $y, 'w' => $w, 'h' => $h,
                'page_w' => $pageWidth, 'page_h' => $pageHeight
            ]);

            // Parsear el PDF para análisis de contenido
            $pdfContent = $this->parser->parseFile($fullPath);
            $pages = $pdfContent->getPages();
            
            if (!isset($pages[$pageNumber - 1])) {
                Log::warning("Página {$pageNumber} no encontrada en el parser.");
                return $result;
            }

            $page = $pages[$pageNumber - 1];
            
            // Obtener dimensiones de la página según el parser
            $parserPageDetails = $page->getDetails();
            
            // Log completo de lo que devuelve el parser
            Log::info(">>> RAW PARSER PAGE DETAILS <<<", [
                'details_type' => gettype($parserPageDetails),
                'details_dump' => print_r($parserPageDetails, true),
                'page_class' => get_class($page),
                'available_methods' => get_class_methods($page)
            ]);
            
            $parserWidth = null;
            $parserHeight = null;
            
            // CRÍTICO: Extraer dimensiones del MediaBox del parser (en puntos)
            // El parser usa puntos, TCPDF puede estar en mm - debemos usar las del parser
            // para convertir coordenadas correctamente
            if (is_array($parserPageDetails) && isset($parserPageDetails['MediaBox'])) {
                $mediaBox = $parserPageDetails['MediaBox'];
                if (is_array($mediaBox) && count($mediaBox) >= 4) {
                    $parserWidth = abs($mediaBox[2] - $mediaBox[0]);
                    $parserHeight = abs($mediaBox[3] - $mediaBox[1]);
                    Log::info(">>> DIMENSIONES DESDE MEDIABOX <<<", [
                        'MediaBox' => $mediaBox,
                        'width' => $parserWidth,
                        'height' => $parserHeight
                    ]);
                }
            }
            
            // Si no pudimos obtener dimensiones del parser, usar las de TCPDF como fallback
            if (!isset($parserWidth) || !isset($parserHeight)) {
                $parserWidth = $pageWidth;
                $parserHeight = $pageHeight;
                Log::warning("No se pudo obtener MediaBox del parser. Usando dimensiones TCPDF como fallback.");
            }
            
            Log::info(">>> COMPARACIÓN DE DIMENSIONES <<<", [
                'TCPDF_pageWidth' => $pageWidth,
                'TCPDF_pageHeight' => $pageHeight,
                'TCPDF_unidad' => ($pageWidth < 300) ? 'mm' : 'pt',
                'Parser_pageWidth' => $parserWidth,
                'Parser_pageHeight' => $parserHeight,
                'Parser_unidad' => 'pt (MediaBox)',
                'diferencia_height' => $pageHeight - $parserHeight,
                'ratio_height' => $parserHeight ? ($pageHeight / $parserHeight) : 'N/A'
            ]);
            
            // Obtener elementos de texto con sus posiciones (matrices de transformación)
            $textElements = $page->getDataTm(); 
            
            $safeY = $y;
            $collisionFound = false;
            $maxContentY_TL = 0; // El Y más alto (más cercano al final en top-left)
            
            // Detectar imágenes/gráficos (firmas, sellos, logos)
            try {
                $pageContent = $page->getContent();
                // Buscar operadores de imagen en el contenido del PDF (Do, BI, etc.)
                if (preg_match_all('/q\s+([\d.\-]+)\s+([\d.\-]+)\s+([\d.\-]+)\s+([\d.\-]+)\s+([\d.\-]+)\s+([\d.\-]+)\s+cm.*?Do/s', $pageContent, $imageMatches, PREG_SET_ORDER)) {
                    foreach ($imageMatches as $match) {
                        // Matriz de transformación: [a b c d e f]
                        $imgWidth = abs((float)$match[1]);
                        $imgHeight = abs((float)$match[4]);
                        $imgX = (float)$match[5];
                        $imgY_BL = (float)$match[6];
                        
                        if ($imgWidth > 20 && $imgHeight > 20) {
                            // CRÍTICO: Convertir de bottom-left a top-left usando dimensiones del parser
                            $imgY_TL = $parserHeight - $imgY_BL - $imgHeight;
                            $imgBottom = $imgY_TL + $imgHeight;
                            
                            // Actualizar contenido máximo
                            if ($imgBottom > $maxContentY_TL) {
                                $maxContentY_TL = $imgBottom;
                                Log::info("Imagen detectada en zona inferior: {$imgWidth}x{$imgHeight} en Y:{$imgY_TL}");
                            }
                            
                            // Verificar colisión con QR
                            $imgMargin = 15; // Margen extra para imágenes (firmas/sellos)
                            $overlapX = ($x - $imgMargin < $imgX + $imgWidth) && ($x + $w + $imgMargin > $imgX);
                            $overlapY = ($safeY - $imgMargin < $imgBottom) && ($safeY + $h + $imgMargin > $imgY_TL);
                            
                            if ($overlapX && $overlapY) {
                                Log::info("Colisión detectada con imagen/gráfico: {$imgWidth}x{$imgHeight} en Y_TL:{$imgY_TL}");
                                $collisionFound = true;
                            }
                        }
                    }
                }
            } catch (\Exception $imgError) {
                Log::warning("No se pudo analizar imágenes: " . $imgError->getMessage());
            }
            
            if (empty($textElements) && !$collisionFound) {
                Log::info("No se detectó contenido textual ni colisiones con imágenes en la página {$pageNumber}.");
                return $result;
            }

            // Procesar elementos de texto solo si existen
            if (!empty($textElements)) {
                foreach ($textElements as $element) {
                if (!isset($element[0][4]) || !isset($element[0][5])) continue;

                $elementX = (float)$element[0][4];
                $elementY_BL = (float)$element[0][5];
                $textData = trim($element[1]);
                
                if (empty($textData)) continue;

                // CRÍTICO: Convertir Y de bottom-left (parser) a top-left usando dimensiones del PARSER
                // El parser usa puntos (MediaBox), NO las dimensiones de TCPDF que pueden estar en mm
                $elementY_TL_Baseline = $parserHeight - $elementY_BL;

                // Estimación mejorada de dimensiones ocupadas por el texto
                // Detectar tamaño de fuente basado en la matriz de transformación si está disponible
                $fontSize = 10; // Por defecto
                if (isset($element[0][0]) && is_numeric($element[0][0])) {
                    $fontSize = max(8, min(18, abs((float)$element[0][0]))); // Limitar entre 8-18pt
                }
                
                $approxTextHeight = $fontSize * 1.5; // Mayor altura para incluir ascendentes/descendentes
                $approxTextWidth = mb_strlen($textData) * ($fontSize * 0.55);

                // El cuadro de texto ocupa desde arriba hasta la baseline + descendentes
                $textTopY = $elementY_TL_Baseline - $approxTextHeight;
                $textBottomY = $elementY_TL_Baseline + 3; // Margen para descendentes

                // Actualizar el contenido más bajo detectado en la página
                if ($textBottomY > $maxContentY_TL) {
                    $maxContentY_TL = $textBottomY;
                }

                // Verificar colisión con el área proyectada del QR
                // Margen de seguridad más estricto para evitar solapamientos
                $margin = 10; // Aumentado de 5 a 10 para mayor separación
                $overlapX = ($x - $margin < $elementX + $approxTextWidth) && ($x + $w + $margin > $elementX);
                $overlapY = ($safeY - $margin < $textBottomY) && ($safeY + $h + $margin > $textTopY);

                if ($overlapX && $overlapY) {
                    Log::info("Colisión detectada con texto: '{$textData}' en [X:{$elementX}, Y_BL:{$elementY_BL}] (Visualmente Y_TL: {$elementY_TL_Baseline})");
                    $collisionFound = true;
                }

                // Casos especiales: Textos críticos que nunca deben ser solapados
                $criticalTexts = ['Fin del Informe', 'Fin del informe', 'FIN DEL INFORME'];
                foreach ($criticalTexts as $criticalText) {
                    if (stripos($textData, $criticalText) !== false) {
                        Log::info(">>> TEXTO CRÍTICO DETECTADO <<<", [
                            'texto' => $textData,
                            'elemento_Y_BL' => $elementY_BL,
                            'elemento_Y_TL_Baseline' => $elementY_TL_Baseline,
                            'textTopY' => $textTopY,
                            'textBottomY' => $textBottomY,
                            'QR_Y' => $safeY,
                            'QR_Y_Bottom' => $safeY + $h,
                            'maxContentY_TL_actual' => $maxContentY_TL,
                            'maxContentY_TL_nuevo' => $textBottomY + 5
                        ]);
                        $collisionFound = true;
                        // Asegurar que maxContentY_TL incluya este elemento crítico con margen extra
                        $maxContentY_TL = max($maxContentY_TL, $textBottomY + 5);
                        break;
                    }
                }
                }
            }

            // CRÍTICO: Convertir maxContentY_TL del sistema del parser (puntos) al sistema de TCPDF
            // para que las comparaciones y cálculos de posicionamiento sean en la misma unidad
            $coordConversionRatio = $pageHeight / $parserHeight; // mm/pt
            $maxContentY_TL_TCPDF = $maxContentY_TL * $coordConversionRatio;
            
            Log::info(">>> CONVERSIÓN DE COORDENADAS <<<", [
                'maxContentY_TL_parser_pt' => $maxContentY_TL,
                'parserHeight_pt' => $parserHeight,
                'pageHeight_TCPDF' => $pageHeight,
                'conversion_ratio' => $coordConversionRatio,
                'maxContentY_TL_TCPDF' => $maxContentY_TL_TCPDF
            ]);
            
            // Si hay colisión, o si la posición objetivo está por debajo del contenido detectado
            // pero muy cerca de él, buscamos el primer espacio libre real.
            if ($collisionFound || ($y + $h > $maxContentY_TL_TCPDF && $y < $maxContentY_TL_TCPDF)) {
                Log::info(">>> CALCULANDO POSICIÓN SEGURA <<<", [
                    'colision_detectada' => $collisionFound,
                    'maxContentY_TL_parser_pt' => $maxContentY_TL,
                    'maxContentY_TL_TCPDF' => $maxContentY_TL_TCPDF,
                    'QR_Y_original' => $y,
                    'QR_Height' => $h,
                    'QR_Bottom_original' => $y + $h,
                    'pageHeight' => $pageHeight
                ]);
                
                // Margen de seguridad aumentado para evitar solapamiento con firmas, sellos y texto
                $marginBelowContent = 30; // Aumentado de 25 a 30 para mayor separación
                $suggestedY = $maxContentY_TL_TCPDF + $marginBelowContent;
                
                Log::info(">>> POSICIÓN SUGERIDA CALCULADA <<<", [
                    'maxContentY_TL' => $maxContentY_TL,
                    'marginBelowContent' => $marginBelowContent,
                    'suggestedY' => $suggestedY,
                    'QR_Bottom_sugerido' => $suggestedY + $h,
                    'espacio_hasta_fin_pagina' => $pageHeight - ($suggestedY + $h)
                ]);
                
                // --- LÓGICA DE TAMAÑO ADAPTATIVO ---
                // Si al moverlo debajo del contenido ya no cabe en la página con el tamaño original,
                // intentamos reducir el tamaño del QR proporcionalmente.
                $availableHeight = $pageHeight - $suggestedY - 15; // 15 es el margen del pie de página físico
                
                if ($h > $availableHeight && $availableHeight > 25) {
                    // El QR es demasiado grande para el espacio restante, reducir al tamaño disponible
                    // pero no menos de 28pt (aprox 1cm), que es el mínimo para ser escaneable.
                    $newSize = max(28.0, $availableHeight);
                    Log::info("Espacio insuficiente para tamaño estándar. Reduciendo QR de {$h} a {$newSize}");
                    $result['width'] = $newSize;
                    $result['height'] = $newSize;
                    $h = $newSize;
                }

                // CLAVE: Posicionar DINÁMICAMENTE según altura real del contenido
                // No usar posición fija de "fondo", sino colocar justo después del contenido detectado
                $contentMargin = 8; // Margen después del contenido (más conservador)
                $dynamicY = $maxContentY_TL_TCPDF + $contentMargin;
                
                Log::info(">>> POSICIONAMIENTO DINÁMICO <<<", [
                    'contenido_hasta' => $maxContentY_TL_TCPDF,
                    'margen_despues_contenido' => $contentMargin,
                    'posicion_dinamica_Y' => $dynamicY,
                    'QR_altura' => $h,
                    'QR_bottom' => $dynamicY + $h,
                    'pageHeight' => $pageHeight
                ]);

                // Verificar si cabe con el tamaño actual
                $bottomPageMargin = 5;
                $availableSpace = $pageHeight - $dynamicY - $bottomPageMargin;
                
                // Reducción AGRESIVA del QR cuando hay poco espacio
                if ($h > $availableSpace) {
                    if ($availableSpace >= 12) {
                        // Espacio suficiente para QR pequeño (12-14mm)
                        $minQrSize = max(10.0, min(14.0, $availableSpace * 0.85));
                    } elseif ($availableSpace >= 8) {
                        // Espacio muy reducido - QR mínimo funcional (8-10mm)
                        $minQrSize = max(8.0, $availableSpace * 0.9);
                    } else {
                        // Espacio crítico - usar lo que hay
                        $minQrSize = max(6.0, $availableSpace * 0.95);
                    }
                    
                    Log::warning("🔄 REDUCCIÓN AGRESIVA: QR de {$h}mm a {$minQrSize}mm (espacio disponible: {$availableSpace}mm)");
                    $result['width'] = $minQrSize;
                    $result['height'] = $minQrSize;
                    $h = $minQrSize;
                }
                
                // Verificar si realmente cabe en la página
                if ($dynamicY + $h > $pageHeight - $bottomPageMargin) {
                        // El contenido llega hasta tan abajo que no hay espacio físico
                        $gap = ($pageHeight - $bottomPageMargin) - ($dynamicY + $h);
                        Log::error(">>> ESPACIO INSUFICIENTE EN ZONA ELEGIDA <<<", [
                            'contenido_hasta_TCPDF' => $maxContentY_TL_TCPDF,
                            'posicion_dinamica_Y' => $dynamicY,
                            'QR_bottom_calculado' => $dynamicY + $h,
                            'pageHeight' => $pageHeight,
                            'espacio_faltante' => $gap,
                            'posicion_usuario_Y' => $y
                        ]);
                        
                        // IMPORTANTE: Respetar la región de página que el usuario eligió
                        // En bulk upload, la primera fila es la plantilla/diseño para todo el lote
                        // NO debemos mover el QR a regiones diferentes (ej: de pie a encabezado)
                        
                        // Intentar micro-ajustes DENTRO de la misma región
                        // Si el usuario puso el QR en el pie (Y > pageHeight/2), mantenerlo en el pie
                        if ($y > $pageHeight / 2) {
                            // Usuario quiere el QR en la zona inferior/pie
                            // Intentar ajustes laterales manteniendo Y lo más cercano posible
                            $bottomY = ($pageHeight - $bottomPageMargin) - $h;
                            $adjustedY = $bottomY; // Lo más abajo posible
                            
                            // Probar desplazamientos laterales pequeños
                            $lateralOffsets = [-15, 15, -30, 30, 0]; // Intentar mover a izq/der
                            $bestX = $x;
                            
                            foreach ($lateralOffsets as $offset) {
                                $testX = $x + $offset;
                                if ($testX >= 10 && $testX + $h <= $pageWidth - 10) {
                                    // Esta posición X está dentro de márgenes
                                    $bestX = $testX;
                                    break;
                                }
                            }
                            
                            Log::warning(">>> AJUSTE MICRO DENTRO DE PIE DE PÁGINA <<<", [
                                'posicion_original_x' => $x,
                                'posicion_original_y' => $y,
                                'posicion_ajustada_x' => $bestX,
                                'posicion_ajustada_y' => $adjustedY,
                                'QR_size' => $h,
                                'nota' => 'Manteniendo región del pie como usuario especificó. Puede haber overlap mínimo.'
                            ]);
                            
                            $result['x'] = $bestX;
                            $result['y'] = $adjustedY;
                        } else {
                            // Usuario quiere el QR en zona superior/media - mantener esa región
                            Log::warning(">>> MANTENIENDO POSICIÓN ORIGINAL <<<", [
                                'posicion_x' => $x,
                                'posicion_y' => $y,
                                'nota' => 'No hay espacio suficiente pero respetando región elegida por usuario'
                            ]);
                            // Mantener posición original del usuario
                            $result['x'] = $x;
                            $result['y'] = $y;
                        }
                    } else {
                        // Hay espacio - colocar dinámicamente después del contenido
                        Log::info(">>> QR COLOCADO DESPUÉS DEL CONTENIDO <<<", [
                            'posicion_Y' => $dynamicY,
                            'contenido_hasta_TCPDF' => $maxContentY_TL_TCPDF,
                            'contenido_hasta_parser_pt' => $maxContentY_TL,
                            'gap' => $dynamicY - $maxContentY_TL_TCPDF,
                            'QR_size' => $h,
                            'QR_bottom' => $dynamicY + $h
                        ]);
                        $result['y'] = $dynamicY;
                    }
                    
                    return $result;
                }
                
                // No hay colisión crítica, usar posición sugerida original
                $result['y'] = $suggestedY;
                return $result;
            Log::info("No se detectaron colisiones críticas. Manteniendo configuración original.");
            return $result;

        } catch (\Exception $e) {
            Log::error("Error en findSafePosition: " . $e->getMessage());
            return $result;
        }
    }

    /**
     * Validar que la posición del QR esté dentro de los límites del PDF
     */
    public function validatePosition(string $pdfPath, array $position, ?string $pdfDisk = 'local'): bool
    {
        try {
            $pdf = new Fpdi();
            if ($pdfDisk === 'final') {
                $filePath = str_replace('final/', '', $pdfPath);
                $fullPdfPath = Storage::disk('final')->path($filePath);
            } else {
                $fullPdfPath = Storage::disk('local')->path($pdfPath);
            }
            
            if (!file_exists($fullPdfPath)) return false;

            $pageCount = $pdf->setSourceFile($fullPdfPath);
            if ($pageCount === 0) return false;
            
            $tplId = $pdf->importPage(1);
            $size = $pdf->getTemplateSize($tplId);
            $pageWidth = $size['width'];
            $pageHeight = $size['height'];

            $SAFE_MARGIN = 5;
            $pdfWidthPx = $pageWidth;
            $pdfHeightPx = $pageHeight;
            
            if ($pageWidth < 1000 && $pageHeight < 1000) {
                $pdfWidthPx = $pageWidth * 2.83465;
                $pdfHeightPx = $pageHeight * 2.83465;
            }
            
            $scaleX = $pdfWidthPx / 595.0;
            $scaleY = $pdfHeightPx / 842.0;
            $safeMarginX = $SAFE_MARGIN * $scaleX;
            $safeMarginY = $SAFE_MARGIN * $scaleY;
            
            $x = ($position['x'] * $pdfWidthPx) / 595.0;
            $y = ($position['y'] * $pdfHeightPx) / 842.0;
            $width = ($position['width'] * $pdfWidthPx) / 595.0;
            $height = ($position['height'] * $pdfHeightPx) / 842.0;
            
            if ($x < $safeMarginX || $y < $safeMarginY || 
                $x + $width > $pdfWidthPx - $safeMarginX || 
                $y + $height > $pdfHeightPx - $safeMarginY) {
                return false;
            }

            return true;

        } catch (\Exception $e) {
            Log::error('Error al validar posición del QR: ' . $e->getMessage());
            return false;
        }
    }
}
