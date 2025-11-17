<?php

namespace App\Services;

use setasign\Fpdi\Tcpdf\Fpdi;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

/**
 * Servicio para procesar PDFs y embebir códigos QR
 * 
 * Usa FPDI y TCPDF para manipular PDFs y agregar códigos QR
 */
class PdfProcessorService
{
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
    public function embedQr(string $pdfPath, string $qrPath, array $position, ?string $pdfDisk = 'local', ?string $qrId = null): string
    {
        try {
            // Crear instancia de FPDI
            $pdf = new Fpdi();
            
            // Obtener rutas completas según el disco
            // Si es 'final', la ruta ya incluye 'final/', sino es 'local' (uploads/...)
            if ($pdfDisk === 'final') {
                // La ruta ya es: final/CE/filename.pdf, solo necesitamos quitar 'final/'
                $filePath = str_replace('final/', '', $pdfPath);
                $fullPdfPath = Storage::disk('final')->path($filePath);
            } else {
                // La ruta es: uploads/CE/CE-12345/filename.pdf
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

            // Importar la primera página del PDF
            // Si el PDF está protegido con contraseña, FPDI lanzará una excepción
            // Por ahora intentamos sin contraseña (la mayoría de PDFs no están protegidos)
            try {
                $pageCount = $pdf->setSourceFile($fullPdfPath);
            } catch (\setasign\Fpdi\PdfParser\CrossReference\CrossReferenceException $e) {
                // Si el PDF está protegido con contraseña, FPDI puede lanzar esta excepción
                $errorMsg = $e->getMessage();
                if (stripos($errorMsg, 'password') !== false || 
                    stripos($errorMsg, 'encrypted') !== false ||
                    stripos($errorMsg, 'protected') !== false) {
                    Log::warning('PDF protegido con contraseña detectado:', [
                        'pdf_path' => $pdfPath,
                        'error' => $errorMsg
                    ]);
                    throw new \Exception("El PDF está protegido con contraseña. No se puede procesar automáticamente. Por favor, desbloquee el PDF antes de subirlo.");
                }
                // Re-lanzar si es otro tipo de error
                throw $e;
            }
            
            if ($pageCount === 0) {
                throw new \Exception("El PDF no tiene páginas válidas");
            }

            // Importar la primera página
            $tplId = $pdf->importPage(1);
            
            // Obtener dimensiones de la página
            $size = $pdf->getTemplateSize($tplId);
            $pageWidth = $size['width'];
            $pageHeight = $size['height'];

            // Convertir posiciones de píxeles del frontend a las unidades del PDF
            // MÉTODO: Usar porcentajes relativos para máxima precisión
            // El frontend siempre envía posiciones en píxeles (595x842 para A4 a 72 DPI)
            // El PDF puede estar en mm (210x297 para A4) o en puntos (595x842)
            
            // Determinar si el PDF está en mm o en puntos
            $isInMm = ($pageWidth < 1000 && $pageHeight < 1000);
            
            // MÉTODO MEJORADO: Usar porcentajes relativos para conversión exacta
            // Esto garantiza que la posición relativa se mantenga exacta sin importar las dimensiones del PDF
            $xPercent = (double)$position['x'] / 595.0;  // Porcentaje de X (0.0 a 1.0)
            $yPercent = (double)$position['y'] / 842.0;  // Porcentaje de Y (0.0 a 1.0)
            $widthPercent = (double)$position['width'] / 595.0;  // Porcentaje de ancho
            $heightPercent = (double)$position['height'] / 842.0; // Porcentaje de alto
            
            // Aplicar porcentajes a las dimensiones reales del PDF
            $x = $xPercent * (double)$pageWidth;
            $y = $yPercent * (double)$pageHeight;
            $width = $widthPercent * (double)$pageWidth;
            $height = $heightPercent * (double)$pageHeight;
            
            // Redondear solo al final con máxima precisión
            if ($isInMm) {
                // PDF en mm: 6 decimales de precisión (0.001mm)
                $x = round($x, 6);
                $y = round($y, 6);
                $width = round($width, 6);
                $height = round($height, 6);
            } else {
                // PDF en puntos: 2 decimales de precisión (0.01 puntos)
                $x = round($x, 2);
                $y = round($y, 2);
                $width = round($width, 2);
                $height = round($height, 2);
            }
            
            Log::info('Conversión de coordenadas (método porcentajes):', [
                'coordenadas_estandar' => $position,
                'porcentajes' => [
                    'x' => $xPercent * 100 . '%',
                    'y' => $yPercent * 100 . '%',
                    'width' => $widthPercent * 100 . '%',
                    'height' => $heightPercent * 100 . '%'
                ],
                'coordenadas_convertidas' => [
                    'x' => $x,
                    'y' => $y,
                    'width' => $width,
                    'height' => $height
                ],
                'dimensiones_pdf' => [
                    'width' => $pageWidth,
                    'height' => $pageHeight,
                    'unidad' => $isInMm ? 'mm' : 'puntos'
                ]
            ]);

            // MARGEN INVISIBLE (área segura) - Configurable (0px = libertad total)
            // 0px desde todos los bordes = libertad total para colocar el QR
            // Convertir el margen de 0px del espacio estándar (595x842) al espacio real del PDF
            $SAFE_MARGIN_STANDARD = 0; // 0px en el espacio estándar = libertad total
            $safeMarginX = ($SAFE_MARGIN_STANDARD * $pageWidth) / 595.0;
            $safeMarginY = ($SAFE_MARGIN_STANDARD * $pageHeight) / 842.0;
            
            // VALIDACIÓN SIMPLE: Solo verificar que esté dentro del PDF (sin margen si SAFE_MARGIN = 0)
            // NO mover, NO ajustar, solo validar y rechazar si está fuera
            // El frontend ya controla el límite visual
            // El backend solo previene la creación de páginas adicionales
            $qrBottom = $y + $height;
            $qrRight = $x + $width;
            
            // Validar que el QR esté completamente dentro del PDF (con margen opcional)
            if ($x < $safeMarginX || $y < $safeMarginY || 
                $qrRight > $pageWidth - $safeMarginX || 
                $qrBottom > $pageHeight - $safeMarginY) {
                if ($SAFE_MARGIN_STANDARD > 0) {
                    Log::warning('QR fuera del área segura - Rechazado (NO se moverá):', [
                        'coordenadas_recibidas' => ['x' => $x, 'y' => $y, 'width' => $width, 'height' => $height],
                        'qrBottom' => $qrBottom, 'qrRight' => $qrRight,
                        'pageWidth' => $pageWidth, 'pageHeight' => $pageHeight,
                        'safeMarginX' => $safeMarginX, 'safeMarginY' => $safeMarginY
                    ]);
                    throw new \Exception("El QR está fuera del área segura. Ajusta la posición en el editor.");
                } else {
                    // Con margen 0, solo validar que esté dentro del PDF
                    if ($x < 0 || $y < 0 || $qrRight > $pageWidth || $qrBottom > $pageHeight) {
                        Log::warning('QR fuera del PDF - Rechazado:', [
                            'coordenadas_recibidas' => ['x' => $x, 'y' => $y, 'width' => $width, 'height' => $height],
                            'qrBottom' => $qrBottom, 'qrRight' => $qrRight,
                            'pageWidth' => $pageWidth, 'pageHeight' => $pageHeight
                        ]);
                        throw new \Exception("El QR está fuera del área del PDF. Ajusta la posición en el editor.");
                    }
                }
            }
            
            // Si está dentro del área segura, usar las coordenadas EXACTAS sin modificar
            // El QR se colocará exactamente donde el usuario lo posicionó

            // CRÍTICO: Deshabilitar auto page break para evitar páginas adicionales
            // TCPDF puede crear páginas automáticamente si detecta contenido cerca del borde
            $pdf->SetAutoPageBreak(false, 0);
            
            // CRÍTICO: Agregar SOLO UNA página (la primera del PDF original)
            // No crear páginas adicionales bajo ninguna circunstancia
            $pdf->AddPage($size['orientation'], [$pageWidth, $pageHeight]);
            
            Log::info('Página agregada al PDF:', [
                'orientation' => $size['orientation'],
                'dimensions' => [$pageWidth, $pageHeight],
                'page_count_before' => $pdf->getNumPages(),
                'auto_page_break_disabled' => true
            ]);
            
            // Usar la plantilla de la página original
            $pdf->useTemplate($tplId, 0, 0, $pageWidth, $pageHeight, true);
            
            // Verificar inmediatamente después de usar la plantilla que solo hay 1 página
            $pageCountAfterTemplate = $pdf->getNumPages();
            if ($pageCountAfterTemplate > 1) {
                Log::error('ERROR: Se crearon páginas adicionales después de useTemplate', [
                    'page_count' => $pageCountAfterTemplate
                ]);
                // Eliminar páginas adicionales si las hay
                while ($pdf->getNumPages() > 1) {
                    $pdf->deletePage($pdf->getNumPages());
                }
            }

            // Insertar el QR en las coordenadas especificadas
            // IMPORTANTE: TCPDF Image() usa Y desde ARRIBA (top-left origin), NO desde abajo
            // El frontend también envía Y desde arriba, así que NO necesitamos invertir
            // Solo usamos directamente las coordenadas convertidas
            $pdfY = $y; // Usar Y directamente (ya está en mm o puntos, desde arriba)
            
            // Log detallado para debugging - VERIFICAR CONVERSIÓN EXACTA
            Log::info('=== INSERTANDO QR EN PDF (CONVERSIÓN DETALLADA) ===', [
                'coordenadas_recibidas_estandar' => [
                    'x' => $position['x'],
                    'y' => $position['y'],
                    'width' => $position['width'],
                    'height' => $position['height']
                ],
                'dimensiones_pdf_real' => [
                    'width' => $pageWidth,
                    'height' => $pageHeight,
                    'unidad' => $isInMm ? 'mm' : 'puntos',
                    'es_estandar' => ($pageWidth == 595 && $pageHeight == 842) || 
                                    ($pageWidth == 210 && $pageHeight == 297) // A4 estándar
                ],
                'factores_conversion' => [
                    'scaleX' => $isInMm ? ($pageWidth / 595.0) : ($pageWidth / 595.0),
                    'scaleY' => $isInMm ? ($pageHeight / 842.0) : ($pageHeight / 842.0),
                    'formula_x' => "({$position['x']} * {$pageWidth}) / 595.0 = " . (($position['x'] * $pageWidth) / 595.0),
                    'formula_y' => "({$position['y']} * {$pageHeight}) / 842.0 = " . (($position['y'] * $pageHeight) / 842.0)
                ],
                'coordenadas_convertidas' => [
                    'x' => $x,
                    'y' => $y,
                    'width' => $width,
                    'height' => $height,
                    'x_antes_redondeo' => ($position['x'] * $pageWidth) / 595.0,
                    'y_antes_redondeo' => ($position['y'] * $pageHeight) / 842.0
                ],
                'coordenadas_finales_tcpdf' => [
                    'x' => $x,
                    'y' => $pdfY,
                    'width' => $width,
                    'height' => $height,
                    'qr_bottom' => $pdfY + $height,
                    'qr_right' => $x + $width
                ],
                'verificacion_posicion' => [
                    'x_porcentaje' => ($x / $pageWidth) * 100 . '%',
                    'y_porcentaje' => ($pdfY / $pageHeight) * 100 . '%',
                    'x_estandar_porcentaje' => ($position['x'] / 595) * 100 . '%',
                    'y_estandar_porcentaje' => ($position['y'] / 842) * 100 . '%',
                    'coinciden_porcentajes' => abs((($x / $pageWidth) * 100) - (($position['x'] / 595) * 100)) < 0.1 &&
                                              abs((($pdfY / $pageHeight) * 100) - (($position['y'] / 842) * 100)) < 0.1
                ],
                'qr_path' => $fullQrPath,
                'qr_exists' => file_exists($fullQrPath),
                'note' => 'TCPDF Image() usa Y desde arriba (top-left origin) - NO se invierte Y'
            ]);
            
            // Verificar que el QR esté completamente dentro de los límites antes de insertar
            // Validación final ABSOLUTA: el QR DEBE estar dentro con margen de seguridad
            $qrBottom = $pdfY + $height;
            $qrRight = $x + $width;
            
            if ($pdfY < $safeMarginY || $x < $safeMarginX || 
                $qrBottom > $pageHeight - $safeMarginY || 
                $qrRight > $pageWidth - $safeMarginX) {
                Log::error('QR fuera de límites antes de insertar (CRÍTICO):', [
                    'x' => $x, 'y' => $pdfY, 'width' => $width, 'height' => $height,
                    'qrBottom' => $qrBottom, 'qrRight' => $qrRight,
                    'pageWidth' => $pageWidth, 'pageHeight' => $pageHeight,
                    'safeMarginX' => $safeMarginX, 'safeMarginY' => $safeMarginY,
                    'withinBounds' => [
                        'x' => $x >= $safeMarginX,
                        'y' => $pdfY >= $safeMarginY,
                        'right' => $qrRight <= $pageWidth - $safeMarginX,
                        'bottom' => $qrBottom <= $pageHeight - $safeMarginY
                    ]
                ]);
                throw new \Exception("El QR está fuera de los límites del área segura. Por favor, ajusta la posición.");
            }
            
            // Insertar imagen QR en el PDF
            // TCPDF Image() método: Image($file, $x, $y, $w = 0, $h = 0, ...)
            // TCPDF usa coordenadas desde la esquina superior izquierda (top-left origin)
            // El frontend también envía Y desde arriba, así que usamos directamente
            $pdf->Image(
                $fullQrPath,  // Archivo
                $x,            // X (desde izquierda)
                $pdfY,         // Y (desde arriba, igual que el frontend)
                $width,        // Ancho
                $height        // Alto
            );
            
            // Verificar inmediatamente después de insertar el QR que solo hay 1 página
            $pageCountAfterImage = $pdf->getNumPages();
            if ($pageCountAfterImage > 1) {
                Log::error('ERROR CRÍTICO: Se crearon páginas adicionales después de Image()', [
                    'page_count' => $pageCountAfterImage,
                    'qr_position' => ['x' => $x, 'y' => $pdfY, 'width' => $width, 'height' => $height],
                    'page_dimensions' => ['width' => $pageWidth, 'height' => $pageHeight]
                ]);
                // Eliminar páginas adicionales si las hay
                while ($pdf->getNumPages() > 1) {
                    $pdf->deletePage($pdf->getNumPages());
                }
                Log::warning('Páginas adicionales eliminadas después de Image()');
            }
            
            Log::info('QR insertado exitosamente en PDF', [
                'page_count_after_insert' => $pdf->getNumPages()
            ]);

            // NUEVA ESTRUCTURA OPTIMIZADA: Organizar PDF final igual que original
            // Estructura: final/{TIPO}/{YYYYMM}/{qr_id}/documento.pdf
            $pathParts = explode('/', $pdfPath);
            $documentType = 'OTROS';
            $monthYear = now()->format('Ym'); // Por defecto, mes actual
            $qrIdFromPath = $qrId ?? ''; // Usar qr_id pasado como parámetro o extraer de ruta
            
            // Detectar estructura: nueva (uploads/CE/202511/{qr_id}/) o antigua (uploads/CE/CE-12345/)
            $isNewStructure = false;
            
            // Nueva estructura: uploads/{TIPO}/{YYYYMM}/{qr_id}/documento.pdf
            if (count($pathParts) >= 4) {
                // pathParts[0] = "uploads", pathParts[1] = "CE", pathParts[2] = "202511", pathParts[3] = "{qr_id}"
                if (in_array(strtoupper($pathParts[1] ?? ''), ['CE', 'IN', 'SU'])) {
                    $documentType = strtoupper($pathParts[1]);
                    // Verificar si pathParts[2] es un año/mes (6 dígitos)
                    if (isset($pathParts[2]) && preg_match('/^(\d{6})$/', $pathParts[2], $matches)) {
                        $monthYear = $matches[1]; // YYYYMM
                        $isNewStructure = true;
                        // Extraer qr_id de pathParts[3]
                        if (isset($pathParts[3])) {
                            $qrIdFromPath = $pathParts[3];
                        }
                    }
                }
            }
            
            // Si es estructura antigua, extraer tipo de otra manera
            if (!$isNewStructure && count($pathParts) >= 2) {
                if (in_array(strtoupper($pathParts[1] ?? ''), ['CE', 'IN', 'SU'])) {
                    $documentType = strtoupper($pathParts[1]);
                }
                // En estructura antigua, el mes está en el nombre del archivo
                $originalBasename = basename($pdfPath);
                if (preg_match('/^(\d{6})-\w+-/', $originalBasename, $matches)) {
                    $monthYear = $matches[1]; // YYYYMM del nombre
                }
            }
            
            // Si no tenemos qr_id, intentar extraerlo del nombre del archivo (estructura antigua)
            if (empty($qrIdFromPath)) {
                $originalBasename = basename($pdfPath);
                // Intentar extraer de formato antiguo: 202511-{qr_id}-documento.pdf
                if (preg_match('/^\d{6}-([a-zA-Z0-9]{32})-/', $originalBasename, $matches)) {
                    $qrIdFromPath = $matches[1];
                } else {
                    // Fallback: usar timestamp como identificador único
                    $qrIdFromPath = 'legacy-' . time();
                }
            }
            
            // Nombre del archivo final (sin prefijos)
            $originalBasename = basename($pdfPath);
            // Si el nombre tiene prefijo de estructura antigua, limpiarlo
            $finalFilename = preg_replace('/^\d{6}-[a-zA-Z0-9]{32}-/', '', $originalBasename);
            if ($finalFilename === $originalBasename) {
                // Si no tenía prefijo, usar el nombre original
                $finalFilename = $originalBasename;
            }
            
            // Crear estructura de carpetas: final/{TIPO}/{YYYYMM}/{qr_id}/
            $finalFolder = "{$documentType}/{$monthYear}/{$qrIdFromPath}";
            Storage::disk('final')->makeDirectory($finalFolder);
            
            // Ruta completa del PDF final
            $finalPath = "final/{$finalFolder}/{$finalFilename}";
            $fullFinalPath = Storage::disk('final')->path("{$finalFolder}/{$finalFilename}");

            // CRÍTICO: Verificar que el PDF solo tenga 1 página antes de guardar
            $finalPageCount = $pdf->getNumPages();
            if ($finalPageCount > 1) {
                Log::error('ERROR CRÍTICO: El PDF tiene más de 1 página antes de guardar', [
                    'page_count' => $finalPageCount,
                    'qr_position' => ['x' => $x, 'y' => $y, 'width' => $width, 'height' => $height],
                    'page_dimensions' => ['width' => $pageWidth, 'height' => $pageHeight]
                ]);
                throw new \Exception("Error: El PDF generado tiene {$finalPageCount} páginas. El QR debe estar más arriba.");
            }
            
            // Guardar el PDF final
            $pdf->Output($fullFinalPath, 'F');
            
            // Verificar que el PDF guardado tenga solo 1 página
            $verifyPdf = new Fpdi();
            $verifyPageCount = $verifyPdf->setSourceFile($fullFinalPath);
            if ($verifyPageCount > 1) {
                Log::error('ERROR CRÍTICO: PDF guardado tiene más de 1 página', [
                    'page_count' => $verifyPageCount,
                    'final_path' => $fullFinalPath
                ]);
                throw new \Exception("Error: El PDF guardado tiene {$verifyPageCount} páginas.");
            }
            
            Log::info('PDF guardado correctamente con 1 página', [
                'page_count' => $verifyPageCount,
                'final_path' => $finalPath
            ]);

            return $finalPath;

        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();
            \Log::error('Error al procesar PDF: ' . $errorMessage, [
                'pdf_path' => $pdfPath,
                'qr_path' => $qrPath,
                'position' => $position,
                'trace' => $e->getTraceAsString()
            ]);
            
            // Preservar el mensaje original para que el frontend pueda detectar errores de FPDI
            // Si el error es de compresión de FPDI, mantener el mensaje original
            if (stripos($errorMessage, 'compression') !== false || 
                stripos($errorMessage, 'not supported by the free parser') !== false ||
                stripos($errorMessage, 'FPDI') !== false) {
                throw new \Exception($errorMessage);
            }
            
            throw new \Exception("Error al embebir QR en PDF: " . $errorMessage);
        }
    }

    /**
     * Validar que la posición del QR esté dentro de los límites del PDF
     * 
     * @param string $pdfPath Ruta del PDF (puede ser uploads/... o final/...)
     * @param array $position Posición propuesta
     * @param string|null $pdfDisk Disco donde está el PDF ('local' o 'final')
     * @return bool True si la posición es válida
     */
    public function validatePosition(string $pdfPath, array $position, ?string $pdfDisk = 'local'): bool
    {
        try {
            $pdf = new Fpdi();
            
            // Obtener ruta completa según el disco
            if ($pdfDisk === 'final') {
                // La ruta ya es: final/CE/filename.pdf, solo necesitamos quitar 'final/'
                $filePath = str_replace('final/', '', $pdfPath);
                $fullPdfPath = Storage::disk('final')->path($filePath);
            } else {
                // La ruta es: uploads/CE/CE-12345/filename.pdf
                $fullPdfPath = Storage::disk('local')->path($pdfPath);
            }
            
            if (!file_exists($fullPdfPath)) {
                Log::warning("PDF no encontrado para validación: {$fullPdfPath}");
                return false;
            }

            $pageCount = $pdf->setSourceFile($fullPdfPath);
            if ($pageCount === 0) {
                Log::warning("PDF sin páginas para validación: {$fullPdfPath}");
                return false;
            }
            
            $tplId = $pdf->importPage(1);
            $size = $pdf->getTemplateSize($tplId);
            
            $pageWidth = $size['width'];
            $pageHeight = $size['height'];

            Log::info('Validando posición del QR:', [
                'pdf_path' => $pdfPath,
                'page_width' => $pageWidth,
                'page_height' => $pageHeight,
                'position' => $position
            ]);

            // MARGEN INVISIBLE (área segura) - Como en iLovePDF
            // 5px desde todos los bordes para evitar que el QR cause páginas adicionales
            // El frontend envía coordenadas en el espacio estándar 595x842
            // Reducido de 20px a 5px para permitir más flexibilidad cerca del footer
            $SAFE_MARGIN = 5; // 5px de margen invisible (igual que en el frontend)
            
            // Convertir dimensiones del PDF a píxeles (72 DPI) si están en mm
            // Si las dimensiones son muy pequeñas (< 1000), asumimos que están en mm
            $pdfWidthPx = $pageWidth;
            $pdfHeightPx = $pageHeight;
            
            if ($pageWidth < 1000 && $pageHeight < 1000) {
                // Están en mm, convertir a píxeles (1mm = 2.83465px a 72 DPI)
                $pdfWidthPx = $pageWidth * 2.83465;
                $pdfHeightPx = $pageHeight * 2.83465;
            }
            
            // Convertir el margen invisible del espacio estándar (595x842) al espacio real del PDF
            $scaleX = $pdfWidthPx / 595.0;
            $scaleY = $pdfHeightPx / 842.0;
            $safeMarginX = $SAFE_MARGIN * $scaleX;
            $safeMarginY = $SAFE_MARGIN * $scaleY;
            
            // Convertir posición del espacio estándar al espacio real del PDF
            $x = ($position['x'] * $pdfWidthPx) / 595.0;
            $y = ($position['y'] * $pdfHeightPx) / 842.0;
            $width = ($position['width'] * $pdfWidthPx) / 595.0;
            $height = ($position['height'] * $pdfHeightPx) / 842.0;
            
            // Validar que el QR esté dentro del área segura (margen invisible)
            if ($x < $safeMarginX || $y < $safeMarginY || 
                $x + $width > $pdfWidthPx - $safeMarginX || 
                $y + $height > $pdfHeightPx - $safeMarginY) {
                Log::warning('Posición del QR fuera del área segura (margen invisible)', [
                    'position' => $position,
                    'pdf_dimensions' => ['width' => $pdfWidthPx, 'height' => $pdfHeightPx],
                    'safe_margin' => ['x' => $safeMarginX, 'y' => $safeMarginY],
                    'calculated_position' => ['x' => $x, 'y' => $y, 'width' => $width, 'height' => $height]
                ]);
                return false;
            }

            // La validación del margen invisible ya cubre todos los casos
            // No necesitamos validaciones adicionales porque el margen invisible garantiza
            // que el QR esté dentro del área segura y no cause páginas adicionales

            Log::info('Posición del QR válida');
            return true;

        } catch (\Exception $e) {
            Log::error('Error al validar posición del QR: ' . $e->getMessage(), [
                'pdf_path' => $pdfPath,
                'position' => $position,
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }
}

