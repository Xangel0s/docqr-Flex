<?php

namespace App\Http\Controllers;

use App\Models\QrFile;
use App\Services\QrGeneratorService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

/**
 * Controlador para subir PDFs y generar códigos QR
 */
class UploadController extends Controller
{
    protected $qrGenerator;

    public function __construct(QrGeneratorService $qrGenerator)
    {
        $this->qrGenerator = $qrGenerator;
    }

    /**
     * Subir PDF y generar código QR
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function upload(Request $request): JsonResponse
    {
        try {
            // Log de información del request para debugging
            Log::info('Upload request recibido:', [
                'has_file' => $request->hasFile('file'),
                'file_name' => $request->hasFile('file') ? $request->file('file')->getClientOriginalName() : 'N/A',
                'file_size' => $request->hasFile('file') ? $request->file('file')->getSize() : 'N/A',
                'file_mime' => $request->hasFile('file') ? $request->file('file')->getMimeType() : 'N/A',
                'folder_name' => $request->input('folder_name'),
                'all_inputs' => array_keys($request->all())
            ]);

            // Validar request
            $validator = Validator::make($request->all(), [
                'file' => 'required|file|mimes:pdf|max:10240', // Máximo 10MB
                'folder_name' => 'required|string|max:100',
            ]);

            if ($validator->fails()) {
                Log::warning('Validación fallida en upload:', [
                    'errors' => $validator->errors()->toArray(),
                    'request_data' => [
                        'has_file' => $request->hasFile('file'),
                        'folder_name' => $request->input('folder_name')
                    ]
                ]);
                
                return response()->json([
                    'success' => false,
                    'message' => 'Error de validación',
                    'errors' => $validator->errors()
                ], 422);
            }

            $file = $request->file('file');
            $folderName = $request->input('folder_name');

            Log::info('Validando PDF:', [
                'file_name' => $file->getClientOriginalName(),
                'file_size' => $file->getSize(),
                'file_path' => $file->getRealPath()
            ]);

            // Validar integridad del PDF (que no esté corrupto)
            $integrityCheck = $this->validatePdfIntegrity($file);
            if (!$integrityCheck['valid']) {
                Log::warning('PDF corrupto o inválido detectado:', [
                    'message' => $integrityCheck['message'],
                    'file_name' => $file->getClientOriginalName(),
                    'error' => $integrityCheck['error'] ?? 'N/A'
                ]);
                
                return response()->json([
                    'success' => false,
                    'message' => $integrityCheck['message']
                ], 422);
            }
            
            // Validar que el PDF tenga solo 1 página
            $pdfInfo = $this->validatePdfPages($file);
            if (!$pdfInfo['valid']) {
                Log::warning('Validación de PDF fallida:', [
                    'message' => $pdfInfo['message'],
                    'file_name' => $file->getClientOriginalName(),
                    'pages_detected' => $pdfInfo['pages'] ?? 'N/A'
                ]);
                
                return response()->json([
                    'success' => false,
                    'message' => $pdfInfo['message']
                ], 422);
            }

            Log::info('PDF validado correctamente:', [
                'pages' => $pdfInfo['pages'] ?? 1,
                'file_name' => $file->getClientOriginalName()
            ]);

            // Generar ID único para el QR (verificar que no exista)
            // Cada documento tiene su propio QR único, incluso si es el mismo archivo
            // Sistema automático: si existe, genera otro sin que el usuario se dé cuenta
            Log::info('🔵 PASO 1: Generando QR ID único...');
            $qrId = $this->generateUniqueQrId();
            Log::info('✅ PASO 1: QR ID generado:', ['qr_id' => $qrId]);

            // Extraer el tipo de documento del folder_name (CE, IN, SU)
            Log::info('🔵 PASO 2: Extrayendo tipo de documento...');
            $documentType = \App\Models\QrFile::extractDocumentType($folderName);
            
            // Obtener mes y año en formato YYYYMM (ej: 202511 para noviembre 2025)
            $monthYear = now()->format('Ym'); // 202511, 202512, 202601, etc.
            
            // NUEVA ESTRUCTURA OPTIMIZADA: uploads/{TIPO}/{YYYYMM}/{qr_id}/
            // Ejemplo: uploads/CE/202511/{qr_id}/documento.pdf
            // Ventajas: Organización por fecha, cada documento en su carpeta, más escalable
            $storageFolder = "uploads/{$documentType}/{$monthYear}/{$qrId}";
            Log::info('✅ PASO 2: Carpeta de destino:', ['folder' => $storageFolder]);
            
            // Asegurar que la carpeta existe (crea todas las subcarpetas necesarias)
            Log::info('🔵 PASO 3: Creando carpetas...');
            Storage::disk('local')->makeDirectory($storageFolder);
            Log::info('✅ PASO 3: Carpetas creadas');

            // Guardar el PDF con nombre original (sin prefijos, más limpio)
            // Ejemplo: documento.pdf (dentro de uploads/CE/202511/{qr_id}/)
            Log::info('🔵 PASO 4: Guardando archivo PDF...');
            $originalFilename = $file->getClientOriginalName();
            $filename = $originalFilename; // Nombre original sin modificaciones
            $filePath = $file->storeAs($storageFolder, $filename, 'local');
            $fileSize = $file->getSize();
            Log::info('✅ PASO 4: Archivo guardado:', ['path' => $filePath, 'size' => $fileSize]);

            // Generar URL para el QR (usar helper que respeta protocolo de solicitud)
            Log::info('🔵 PASO 5: Generando URL para el QR...');
            $qrUrl = \App\Helpers\UrlHelper::url("/api/view/{$qrId}", $request);
            Log::info('✅ PASO 5: URL generada:', ['qr_url' => $qrUrl]);

            // Generar código QR (OBLIGATORIO - solo se guarda si el QR se genera exitosamente)
            Log::info('🔵 PASO 6: Generando imagen QR...');
            try {
                $qrPath = $this->qrGenerator->generate($qrUrl, $qrId);
                Log::info('✅ PASO 6: Imagen QR generada:', ['qr_path' => $qrPath]);
            } catch (\Exception $e) {
                // Si falla la generación del QR, eliminar el PDF subido y retornar error
                Storage::disk('local')->delete($filePath);
                Log::error('❌ ERROR PASO 6: Error al generar QR: ' . $e->getMessage());
                return response()->json([
                    'success' => false,
                    'message' => 'Error al generar código QR: ' . $e->getMessage()
                ], 500);
            }

            // Crear registro en la base de datos SOLO si el QR se generó exitosamente
            // Cada documento tiene su ID único independiente (id auto-incremental + qr_id único)
            Log::info('🔵 PASO 7: Guardando en base de datos...');
            $qrFile = QrFile::create([
                'qr_id' => $qrId, // ID único de 32 caracteres para el QR
                'folder_name' => $folderName,
                'original_filename' => $originalFilename,
                'file_path' => $filePath,
                'qr_path' => $qrPath, // QR generado exitosamente
                'file_size' => $fileSize,
                'status' => 'uploaded', // Estado inicial: subido (aún no tiene QR embebido)
                'scan_count' => 0, // Inicia en 0, solo se incrementa cuando se escanea el QR
            ]);
            Log::info('✅ PASO 7: Guardado en BD exitosamente:', ['id' => $qrFile->id, 'qr_id' => $qrFile->qr_id]);

            // Invalidar cache de estadísticas cuando se crea un nuevo documento
            Cache::forget('documents_stats_v2');

            // URLs públicas a través de la API (escalable para producción en la nube)
            // Usar helper que respeta el protocolo de la solicitud actual (HTTPS si viene de ngrok)
            $pdfUrl = \App\Helpers\UrlHelper::url("/api/files/pdf/{$qrId}", $request);
            $qrImageUrl = \App\Helpers\UrlHelper::url("/api/files/qr/{$qrId}", $request);

            return response()->json([
                'success' => true,
                'message' => 'PDF subido y QR generado exitosamente',
                'data' => [
                    'qr_id' => $qrId,
                    'qr_url' => $qrUrl,
                    'pdf_url' => $pdfUrl,
                    'qr_image_url' => $qrImageUrl,
                    'folder_name' => $folderName,
                    'original_filename' => $originalFilename,
                ]
            ], 201);

        } catch (\Exception $e) {
            Log::error('❌ ERROR CRÍTICO al subir PDF:', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
                'request_data' => [
                    'has_file' => $request->hasFile('file'),
                    'folder_name' => $request->input('folder_name'),
                ]
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Error al procesar el archivo: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Validar integridad del PDF (que no esté corrupto)
     * 
     * @param \Illuminate\Http\UploadedFile $file
     * @return array
     */
    private function validatePdfIntegrity($file): array
    {
        try {
            // Intentar abrir el PDF con FPDI para verificar que no esté corrupto
            $pdf = new \setasign\Fpdi\Tcpdf\Fpdi();
            
            try {
                $pageCount = $pdf->setSourceFile($file->getRealPath());
                
                if ($pageCount === 0) {
                    return [
                        'valid' => false,
                        'message' => 'El archivo PDF está corrupto o no tiene páginas válidas. Por favor, verifica el archivo e intenta nuevamente.',
                        'error' => 'PDF sin páginas'
                    ];
                }
                
                // Intentar importar la primera página para verificar integridad completa
                $tplId = $pdf->importPage(1);
                $size = $pdf->getTemplateSize($tplId);
                
                if (!$size || !isset($size['width']) || !isset($size['height'])) {
                    return [
                        'valid' => false,
                        'message' => 'El archivo PDF está corrupto. No se pueden leer las dimensiones de la página. Por favor, verifica el archivo e intenta nuevamente.',
                        'error' => 'No se pueden leer dimensiones'
                    ];
                }
                
                return [
                    'valid' => true,
                    'pages' => $pageCount
                ];
                
            } catch (\setasign\Fpdi\PdfParser\CrossReference\CrossReferenceException $e) {
                $errorMsg = $e->getMessage();
                if (stripos($errorMsg, 'password') !== false || 
                    stripos($errorMsg, 'encrypted') !== false ||
                    stripos($errorMsg, 'protected') !== false) {
                    return [
                        'valid' => false,
                        'message' => 'El PDF está protegido con contraseña. Por favor, desbloquee el PDF antes de subirlo.',
                        'error' => 'PDF protegido con contraseña'
                    ];
                }
                
                // Otro tipo de error de parsing (posible corrupción)
                return [
                    'valid' => false,
                    'message' => 'El archivo PDF está corrupto o no se puede leer correctamente. Por favor, verifica el archivo e intenta nuevamente.',
                    'error' => $errorMsg
                ];
            } catch (\Exception $e) {
                return [
                    'valid' => false,
                    'message' => 'Error al validar el archivo PDF: ' . $e->getMessage() . '. Por favor, verifica que el archivo sea un PDF válido.',
                    'error' => $e->getMessage()
                ];
            }
            
        } catch (\Exception $e) {
            return [
                'valid' => false,
                'message' => 'Error al procesar el archivo PDF. Por favor, verifica que el archivo sea un PDF válido y no esté corrupto.',
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
    private function validatePdfPages($file): array
    {
        try {
            $filePath = $file->getRealPath();
            
            // Verificar que el archivo existe
            if (!file_exists($filePath)) {
                Log::error('Archivo no encontrado para validación:', ['path' => $filePath]);
                return [
                    'valid' => false,
                    'message' => 'El archivo no se pudo leer correctamente'
                ];
            }

            // Leer el contenido del PDF
            $content = file_get_contents($filePath);
            
            if ($content === false || strlen($content) === 0) {
                Log::error('Archivo PDF vacío o no se pudo leer');
                return [
                    'valid' => false,
                    'message' => 'El archivo PDF está vacío o no se pudo leer'
                ];
            }

            // Verificar que es un PDF válido (debe empezar con %PDF)
            if (substr($content, 0, 4) !== '%PDF') {
                Log::warning('Archivo no es un PDF válido:', [
                    'first_bytes' => substr($content, 0, 20)
                ]);
                return [
                    'valid' => false,
                    'message' => 'El archivo no es un PDF válido'
                ];
            }

            // Método 1: Buscar /Type/Page (más preciso)
            $pageCount1 = preg_match_all('/\/Type[\s]*\/Page[^s]/', $content);
            
            // Método 2: Buscar /Page (alternativo)
            $pageCount2 = preg_match_all('/\/Page\W/', $content);
            
            // Método 3: Buscar /Count (número de páginas en el catálogo)
            $pageCount3 = 0;
            if (preg_match('/\/Count[\s]+(\d+)/', $content, $matches)) {
                $pageCount3 = (int)$matches[1];
            }

            // Usar el método que dé un resultado válido
            $pageCount = 0;
            if ($pageCount1 > 0) {
                $pageCount = $pageCount1;
            } elseif ($pageCount2 > 0) {
                $pageCount = $pageCount2;
            } elseif ($pageCount3 > 0) {
                $pageCount = $pageCount3;
            }

            Log::info('Conteo de páginas del PDF:', [
                'method1_type_page' => $pageCount1,
                'method2_page' => $pageCount2,
                'method3_count' => $pageCount3,
                'final_count' => $pageCount,
                'file_size' => strlen($content)
            ]);

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
            Log::error('Excepción al validar PDF:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return [
                'valid' => false,
                'message' => 'Error al validar el PDF: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Generar un qr_id único garantizado
     * 
     * Sistema automático que verifica y regenera si existe colisión
     * El usuario nunca se dará cuenta si hay que regenerar
     * 
     * @param int $maxAttempts Número máximo de intentos (por seguridad)
     * @return string qr_id único de 32 caracteres
     * @throws \Exception Si no se puede generar un ID único después de varios intentos
     */
    private function generateUniqueQrId(int $maxAttempts = 100): string
    {
        $attempts = 0;
        
        do {
            // Generar ID aleatorio de 32 caracteres
            $qrId = Str::random(32);
            $attempts++;
            
            // Verificar si ya existe en la base de datos
            $exists = QrFile::where('qr_id', $qrId)->exists();
            
            // Si existe, se regenera automáticamente (transparente para el usuario)
            if ($exists) {
                Log::warning("QR ID duplicado detectado, regenerando: {$qrId} (intento {$attempts})");
            }
            
            // Protección: evitar loop infinito (aunque es prácticamente imposible)
            if ($attempts >= $maxAttempts) {
                Log::error("No se pudo generar un QR ID único después de {$maxAttempts} intentos");
                throw new \Exception("Error al generar ID único para el QR. Intenta nuevamente.");
            }
            
        } while ($exists);
        
        // Si se regeneró, log informativo (solo para debugging)
        if ($attempts > 1) {
            Log::info("QR ID regenerado después de {$attempts} intentos. QR ID final: {$qrId}");
        }
        
        return $qrId;
    }

    // Método extractDocumentType removido - usar QrFile::extractDocumentType() en su lugar
}

