<?php

namespace App\Services;

/**
 * Servicio para validar integridad y propiedades de archivos PDF
 */
class PdfValidationService
{
    /**
     * Validar integridad del PDF (que no esté corrupto)
     * 
     * @param \Illuminate\Http\UploadedFile $file
     * @return array
     */
    public function validatePdfIntegrity($file): array
    {
        try {
            $filePath = $file->getRealPath();
            $fileSize = $file->getSize();
            
            // Validación básica: verificar que el archivo existe y tiene contenido
            if (!file_exists($filePath)) {
                return [
                    'valid' => false,
                    'message' => 'El archivo no se pudo leer correctamente. Por favor, intenta nuevamente.',
                    'error' => 'Archivo no encontrado'
                ];
            }
            
            // Validar que el archivo tenga contenido
            if ($fileSize === 0) {
                return [
                    'valid' => false,
                    'message' => 'El archivo PDF está vacío. Por favor, verifica el archivo e intenta nuevamente.',
                    'error' => 'Archivo vacío'
                ];
            }
            
            // Validar que sea un PDF válido (verificar header)
            $handle = fopen($filePath, 'rb');
            if ($handle === false) {
                return [
                    'valid' => false,
                    'message' => 'No se pudo leer el archivo. Por favor, verifica los permisos e intenta nuevamente.',
                    'error' => 'No se puede abrir archivo'
                ];
            }
            
            $header = fread($handle, 4);
            fclose($handle);
            
            if ($header !== '%PDF') {
                return [
                    'valid' => false,
                    'message' => 'El archivo no es un PDF válido. Por favor, verifica que el archivo sea un PDF.',
                    'error' => 'No es PDF válido'
                ];
            }
            
            // Para PDFs grandes (>10MB), hacer validación básica y saltar validación FPDI
            // FPDI puede tener problemas con PDFs grandes o complejos
            if ($fileSize > 10 * 1024 * 1024) {
                // Validación básica: solo verificar que es PDF válido
                return [
                    'valid' => true,
                    'pages' => null, // No contamos páginas para PDFs grandes
                    'skip_fpdi_validation' => true
                ];
            }
            
            // Para PDFs pequeños, hacer validación completa con FPDI
            try {
                $pdf = new \setasign\Fpdi\Tcpdf\Fpdi();
                
                try {
                    $pageCount = $pdf->setSourceFile($filePath);
                    
                    if ($pageCount === 0) {
                        // Si FPDI falla pero el archivo es PDF válido, permitirlo
                        return [
                            'valid' => true,
                            'pages' => null,
                            'skip_fpdi_validation' => true,
                            'warning' => 'No se pudo validar completamente con FPDI, pero el archivo parece ser un PDF válido'
                        ];
                    }
                    
                    $tplId = $pdf->importPage(1);
                    $size = $pdf->getTemplateSize($tplId);
                    
                    if (!$size || !isset($size['width']) || !isset($size['height'])) {
                        // Si no se pueden leer dimensiones pero es PDF válido, permitirlo
                        return [
                            'valid' => true,
                            'pages' => $pageCount,
                            'skip_fpdi_validation' => true,
                            'warning' => 'No se pudieron leer las dimensiones, pero el PDF parece válido'
                        ];
                    }
                    
                    return [
                        'valid' => true,
                        'pages' => $pageCount
                    ];
                    
                } catch (\setasign\Fpdi\PdfParser\CrossReference\CrossReferenceException $e) {
                    $errorMsg = $e->getMessage();
                    
                    // Si es PDF protegido con contraseña, rechazar
                    if (stripos($errorMsg, 'password') !== false || 
                        stripos($errorMsg, 'encrypted') !== false ||
                        stripos($errorMsg, 'protected') !== false) {
                        return [
                            'valid' => false,
                            'message' => 'El PDF está protegido con contraseña. Por favor, desbloquee el PDF antes de subirlo.',
                            'error' => 'PDF protegido con contraseña'
                        ];
                    }
                    
                    // Para otros errores de FPDI, si el archivo es PDF válido, permitirlo
                    // FPDI puede fallar con PDFs complejos pero el archivo puede ser válido
                    return [
                        'valid' => true,
                        'pages' => null,
                        'skip_fpdi_validation' => true,
                        'warning' => 'FPDI no pudo procesar el PDF completamente, pero el archivo parece ser un PDF válido'
                    ];
                } catch (\Exception $e) {
                    // Si FPDI falla pero el archivo es PDF válido, permitirlo
                    return [
                        'valid' => true,
                        'pages' => null,
                        'skip_fpdi_validation' => true,
                        'warning' => 'Error en validación FPDI, pero el archivo parece ser un PDF válido: ' . $e->getMessage()
                    ];
                }
                
            } catch (\Exception $e) {
                // Si hay error al crear FPDI pero el archivo es PDF válido, permitirlo
                return [
                    'valid' => true,
                    'pages' => null,
                    'skip_fpdi_validation' => true,
                    'warning' => 'No se pudo validar con FPDI, pero el archivo parece ser un PDF válido'
                ];
            }
            
        } catch (\Exception $e) {
            return [
                'valid' => false,
                'message' => 'Error al procesar el archivo PDF: ' . $e->getMessage() . '. Por favor, verifica que el archivo sea un PDF válido.',
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Validar que el PDF tenga solo 1 página
     * 
     * @param \Illuminate\Http\UploadedFile $file
     * @return array
     */
    public function validatePdfPages($file): array
    {
        try {
            $filePath = $file->getRealPath();
            
            if (!file_exists($filePath)) {
                return [
                    'valid' => false,
                    'message' => 'El archivo no se pudo leer correctamente'
                ];
            }

            $content = file_get_contents($filePath);
            
            if ($content === false || strlen($content) === 0) {
                return [
                    'valid' => false,
                    'message' => 'El archivo PDF está vacío o no se pudo leer'
                ];
            }

            if (substr($content, 0, 4) !== '%PDF') {
                return [
                    'valid' => false,
                    'message' => 'El archivo no es un PDF válido'
                ];
            }

            $pageCount1 = preg_match_all('/\/Type[\s]*\/Page[^s]/', $content);
            $pageCount2 = preg_match_all('/\/Page\W/', $content);
            $pageCount3 = 0;
            if (preg_match('/\/Count[\s]+(\d+)/', $content, $matches)) {
                $pageCount3 = (int)$matches[1];
            }

            $pageCount = 0;
            if ($pageCount1 > 0) {
                $pageCount = $pageCount1;
            } elseif ($pageCount2 > 0) {
                $pageCount = $pageCount2;
            } elseif ($pageCount3 > 0) {
                $pageCount = $pageCount3;
            }

            if ($pageCount === 0) {
                return [
                    'valid' => false,
                    'message' => 'No se pudo determinar el número de páginas del PDF. Asegúrate de que el archivo sea un PDF válido.',
                    'pages' => 0
                ];
            }

            if ($pageCount > 1) {
                return [
                    'valid' => false,
                    'message' => "El PDF debe tener solo 1 página. El archivo tiene {$pageCount} páginas. Por favor, divide el PDF o usa solo la primera página.",
                    'pages' => $pageCount
                ];
            }

            return ['valid' => true, 'pages' => $pageCount];

        } catch (\Exception $e) {
            return [
                'valid' => false,
                'message' => 'Error al validar el PDF: ' . $e->getMessage()
            ];
        }
    }
}

