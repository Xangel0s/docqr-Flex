<?php

namespace App\Services;

use setasign\Fpdi\Tcpdf\Fpdi;
use Illuminate\Support\Facades\Storage;
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
    public function embedQr(string $pdfPath, string $qrPath, array $position, ?string $pdfDisk = 'local', ?string $qrId = null, ?string $documentTitle = null, ?string $folderName = null, ?int $pageNumber = 1): string
    {
        try {
            // Aumentar límites para PDFs grandes (hasta 500MB)
            ini_set('memory_limit', '1024M'); // 1GB para PDFs muy grandes
            set_time_limit(600); // 10 minutos para PDFs grandes para PDFs grandes
            
            // Crear instancia de FPDI
            $pdf = new Fpdi();
            
            // Establecer metadatos del PDF para que el visor muestre el título correcto
            // NOTA: SetProducer() no está disponible en todas las versiones de FPDI/TCPDF
            if ($documentTitle) {
                $pdf->SetTitle($documentTitle);
            } elseif ($folderName) {
                $pdf->SetTitle($folderName);
            }
            $pdf->SetAuthor('Geofal');
            $pdf->SetSubject('Documento con código QR');
            $pdf->SetCreator('Geofal - Sistema de Gestión de Documentos');
            // SetProducer() no está disponible - eliminado para evitar errores
            
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
                    throw new \Exception("El PDF está protegido con contraseña. No se puede procesar automáticamente. Por favor, desbloquee el PDF antes de subirlo.");
                }
                // Re-lanzar si es otro tipo de error
                throw $e;
            }
            
            if ($pageCount === 0) {
                throw new \Exception("El PDF no tiene páginas válidas");
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

            $SAFE_MARGIN_STANDARD = 0;
            $safeMarginX = ($SAFE_MARGIN_STANDARD * $pageWidth) / 595.0;
            $safeMarginY = ($SAFE_MARGIN_STANDARD * $pageHeight) / 842.0;
            
            // Tolerancia para errores de redondeo (0.1 puntos/mm)
            $tolerance = 0.1;
            
            $qrBottom = $y + $height;
            $qrRight = $x + $width;
            
            // Validar con tolerancia para evitar falsos positivos por errores de redondeo
            if ($x < $safeMarginX - $tolerance || $y < $safeMarginY - $tolerance || 
                $qrRight > $pageWidth - $safeMarginX + $tolerance || 
                $qrBottom > $pageHeight - $safeMarginY + $tolerance) {
                if ($SAFE_MARGIN_STANDARD > 0) {
                    throw new \Exception("El QR está fuera del área segura. Ajusta la posición en el editor.");
                } else {
                    // Con SAFE_MARGIN = 0, permitir que esté exactamente en los bordes (con tolerancia)
                    if ($x < -$tolerance || $y < -$tolerance || 
                        $qrRight > $pageWidth + $tolerance || 
                        $qrBottom > $pageHeight + $tolerance) {
                        throw new \Exception("El QR está fuera del área del PDF. Ajusta la posición en el editor.");
                    }
                }
            }
            // TCPDF puede crear páginas automáticamente si detecta contenido cerca del borde
            $pdf->SetAutoPageBreak(false, 0);
            
            // Agregar TODAS las páginas del PDF original
            for ($i = 1; $i <= $pageCount; $i++) {
                $currentPageSize = $pageSizes[$i];
                $pdf->AddPage($currentPageSize['orientation'], [$currentPageSize['width'], $currentPageSize['height']]);
                $pdf->useTemplate($pageTemplates[$i], 0, 0, $currentPageSize['width'], $currentPageSize['height'], true);
            }
            
            // Verificar que el número de páginas sea correcto
            $pageCountAfterTemplate = $pdf->getNumPages();
            if ($pageCountAfterTemplate !== $pageCount) {
                Log::error('ERROR: El número de páginas no coincide después de copiar', [
                    'expected' => $pageCount,
                    'actual' => $pageCountAfterTemplate
                ]);
                // Intentar corregir eliminando páginas extra
                while ($pdf->getNumPages() > $pageCount) {
                    $pdf->deletePage($pdf->getNumPages());
                }
            }

            // Cambiar a la página donde se colocará el QR
            // CRÍTICO: Para 1 página, asegurar que estamos en la página correcta
            // TCPDF puede tener problemas con setPage(1) si solo hay 1 página
            if ($pageCount === 1) {
                // Si solo hay 1 página, no necesitamos setPage, pero lo hacemos por consistencia
                // Asegurar que estamos en la página 1
                $currentPage = $pdf->getPage();
                if ($currentPage !== 1) {
                    $pdf->setPage(1);
                }
            } else {
                // Para múltiples páginas, usar setPage normalmente
                $pdf->setPage($pageNumber);
            }

            // Usar coordenadas exactas del frontend (sin offset de calibración)
            // El frontend calcula las coordenadas correctamente en el espacio estándar (595x842)
            // y las convertimos al espacio real del PDF usando porcentajes
            $pdfY = $y;
            // NOTA: Se eliminó el offset de +15px que causaba desplazamiento incorrecto
            
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
                    'page_number' => $pageNumber,
                    'withinBounds' => [
                        'x' => $x >= $safeMarginX,
                        'y' => $pdfY >= $safeMarginY,
                        'right' => $qrRight <= $pageWidth - $safeMarginX,
                        'bottom' => $qrBottom <= $pageHeight - $safeMarginY
                    ]
                ]);
                throw new \Exception("El QR está fuera de los límites del área segura. Por favor, ajusta la posición.");
            }
            
            // Insertar imagen QR en la página especificada
            // TCPDF Image() método: Image($file, $x, $y, $w = 0, $h = 0, ...)
            // TCPDF usa coordenadas desde la esquina superior izquierda (top-left origin)
            // El frontend también envía Y desde arriba, así que usamos directamente
            
            // LÓGICA MATEMÁTICA EXACTA: Usar porcentajes puros para conversión base
            // Las coordenadas $x y $pdfY ya están calculadas usando porcentajes relativos
            // (xPercent * pageWidth, yPercent * pageHeight) - sin offsets adicionales
            
            // CRÍTICO: Forzar que el QR sea cuadrado usando width para ambos parámetros
            // Esto garantiza que width === height siempre
            $finalWidth = $width;  // Usar width como referencia
            $finalHeight = $width; // Forzar igual a width (cuadrado perfecto)
            
            // Log antes de insertar QR (especialmente importante para 1 página)
            Log::info('Insertando QR en PDF:', [
                'page_count' => $pageCount,
                'page_number' => $pageNumber,
                'current_page_before' => $pdf->getPage(),
                'qr_position' => ['x' => $x, 'y' => $pdfY, 'width' => $finalWidth, 'height' => $finalHeight],
                'page_dimensions' => ['width' => $pageWidth, 'height' => $pageHeight],
                'qr_path' => $fullQrPath,
                'qr_exists' => file_exists($fullQrPath)
            ]);
            
            // Asegurar que estamos en la página correcta antes de insertar
            if ($pageCount === 1) {
                // Para 1 página, asegurar que estamos en página 1
                if ($pdf->getPage() !== 1) {
                    $pdf->setPage(1);
                }
            } else {
                // Para múltiples páginas, asegurar que estamos en la página correcta
                if ($pdf->getPage() !== $pageNumber) {
                    $pdf->setPage($pageNumber);
                }
            }
            
            $pdf->Image(
                $fullQrPath,
                $x,
                $pdfY,
                $finalWidth,
                $finalHeight
            );
            
            // Log después de insertar QR
            Log::info('QR insertado en PDF:', [
                'page_count' => $pageCount,
                'page_number' => $pageNumber,
                'current_page_after' => $pdf->getPage(),
                'total_pages_after' => $pdf->getNumPages()
            ]);
            
            // Verificar que el número de páginas se mantenga igual
            $pageCountAfterImage = $pdf->getNumPages();
            if ($pageCountAfterImage !== $pageCount) {
                Log::error('ERROR CRÍTICO: El número de páginas cambió después de Image()', [
                    'expected' => $pageCount,
                    'actual' => $pageCountAfterImage,
                    'qr_position' => ['x' => $x, 'y' => $pdfY, 'width' => $width, 'height' => $height],
                    'page_dimensions' => ['width' => $pageWidth, 'height' => $pageHeight],
                    'page_number' => $pageNumber
                ]);
                // Intentar corregir eliminando páginas extra
                while ($pdf->getNumPages() > $pageCount) {
                    $pdf->deletePage($pdf->getNumPages());
                }
            }

            // NUEVA ESTRUCTURA OPTIMIZADA: Organizar PDF final igual que original
            // Estructura: final/{TIPO}/{YYYYMM}/{qr_id}/documento.pdf
            $pathParts = explode('/', $pdfPath);
            $documentType = 'OTROS';
            $monthYear = now()->format('Ym'); // Por defecto, mes actual
            $qrIdFromPath = $qrId ?? ''; // Usar qr_id pasado como parámetro o extraer de ruta
            
            $isNewStructure = false;
            
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
            
            if (empty($qrIdFromPath)) {
                $originalBasename = basename($pdfPath);
                if (preg_match('/^\d{6}-([a-zA-Z0-9]{32})-/', $originalBasename, $matches)) {
                    $qrIdFromPath = $matches[1];
                } else {
                    $qrIdFromPath = 'legacy-' . time();
                }
            }
            
            $originalBasename = basename($pdfPath);
            $finalFilename = preg_replace('/^\d{6}-[a-zA-Z0-9]{32}-/', '', $originalBasename);
            if ($finalFilename === $originalBasename) {
                $finalFilename = $originalBasename;
            }
            
            // Crear estructura de carpetas: final/{TIPO}/{YYYYMM}/{qr_id}/
            $finalFolder = "{$documentType}/{$monthYear}/{$qrIdFromPath}";
            Storage::disk('final')->makeDirectory($finalFolder);
            
            // Ruta completa del PDF final
            // IMPORTANTE: NO incluir "final/" en la ruta porque Storage::disk('final') ya apunta a storage/app/final/
            $finalPath = "{$finalFolder}/{$finalFilename}";
            $fullFinalPath = Storage::disk('final')->path($finalPath);

            // Verificar que el número de páginas sea correcto antes de guardar
            $finalPageCount = $pdf->getNumPages();
            if ($finalPageCount !== $pageCount) {
                Log::error('ERROR: El número de páginas no coincide antes de guardar', [
                    'expected' => $pageCount,
                    'actual' => $finalPageCount,
                    'qr_position' => ['x' => $x, 'y' => $pdfY, 'width' => $width, 'height' => $height],
                    'page_dimensions' => ['width' => $pageWidth, 'height' => $pageHeight],
                    'page_number' => $pageNumber
                ]);
                // Intentar corregir eliminando páginas extra o agregando las faltantes
                while ($pdf->getNumPages() > $pageCount) {
                    $pdf->deletePage($pdf->getNumPages());
                }
                // Si faltan páginas, no podemos agregarlas fácilmente, así que lanzar error
                if ($pdf->getNumPages() < $pageCount) {
                    throw new \Exception("Error: El PDF generado tiene {$pdf->getNumPages()} páginas, se esperaban {$pageCount}.");
                }
            }
            
            // Guardar el PDF final (soporta múltiples páginas)
            $pdf->Output($fullFinalPath, 'F');
            
            // Verificar que el PDF guardado tenga el número correcto de páginas
            try {
                $verifyPdf = new Fpdi();
                $verifyPageCount = $verifyPdf->setSourceFile($fullFinalPath);
                if ($verifyPageCount !== $pageCount) {
                    Log::warning('ADVERTENCIA: PDF guardado tiene número de páginas diferente', [
                        'expected' => $pageCount,
                        'actual' => $verifyPageCount,
                        'final_path' => $fullFinalPath
                    ]);
                    // No lanzar excepción, solo loggear - el PDF puede estar bien
                }
            } catch (\Exception $e) {
                // Si no se puede verificar, continuar - el PDF puede estar bien
                Log::warning('No se pudo verificar el número de páginas del PDF guardado: ' . $e->getMessage());
            }
            

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
                return false;
            }

            $pageCount = $pdf->setSourceFile($fullPdfPath);
            if ($pageCount === 0) {
                return false;
            }
            
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
            Log::error('Error al validar posición del QR: ' . $e->getMessage(), [
                'pdf_path' => $pdfPath,
                'position' => $position,
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }
}

