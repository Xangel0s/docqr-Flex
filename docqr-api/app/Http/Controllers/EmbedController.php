<?php

namespace App\Http\Controllers;

use App\Models\QrFile;
use App\Services\PdfProcessorService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use App\Helpers\CacheHelper;

/**
 * Controlador para embebir QR en PDF con posición específica
 */
class EmbedController extends Controller
{
    protected $pdfProcessor;

    public function __construct(PdfProcessorService $pdfProcessor)
    {
        $this->pdfProcessor = $pdfProcessor;
    }

    /**
     * Embebir QR en PDF con posición específica
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function embed(Request $request): JsonResponse
    {
        try {
            // Validar request
            // No usar 'exists' con soft deletes, validaremos manualmente
            $validator = Validator::make($request->all(), [
                'qr_id' => 'required|string|max:255',
                'x' => 'required|numeric|min:0',
                'y' => 'required|numeric|min:0',
                'width' => 'required|numeric|min:50|max:300',
                'height' => 'required|numeric|min:50|max:300',
            ]);

            if ($validator->fails()) {
                Log::error('Error de validación en embed:', [
                    'request' => $request->all(),
                    'errors' => $validator->errors()->toArray()
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'Error de validación: ' . $validator->errors()->first(),
                    'errors' => $validator->errors()
                ], 422);
            }

            // Buscar el archivo QR (incluyendo eliminados con soft delete)
            $qrId = $request->input('qr_id');
            
            if (!\App\Helpers\QrIdValidator::isValid($qrId)) {
                return response()->json([
                    'success' => false,
                    'message' => 'ID de documento inválido'
                ], 400);
            }
            
            // Aumentar límites para procesar PDFs grandes (hasta 500MB)
            ini_set('memory_limit', '1024M'); // 1GB para PDFs muy grandes
            set_time_limit(600); // 10 minutos para PDFs grandes
            
            $qrFile = QrFile::withTrashed()->where('qr_id', $qrId)->first();

            if (!$qrFile) {
                // Verificar si existe en la BD sin soft delete
                $existsInDb = QrFile::where('qr_id', $qrId)->exists();
                Log::error('Documento no encontrado para embed:', [
                    'qr_id' => $qrId,
                    'exists_in_db' => $existsInDb,
                    'all_qr_ids' => QrFile::pluck('qr_id')->take(5)->toArray()
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'Documento no encontrado. Verifica que el documento exista en la base de datos.'
                ], 404);
            }
            
            // Si el documento está eliminado (soft delete), restaurarlo
            if ($qrFile->trashed()) {
                $qrFile->restore();
            }
            
            $pdfPathToUse = null;
            $pdfDiskToUse = null;
            
            if ($qrFile->file_path && Storage::disk('local')->exists($qrFile->file_path)) {
                $pdfPathToUse = $qrFile->file_path;
                $pdfDiskToUse = 'local';
            } elseif ($qrFile->final_path) {
                $pdfPathToUse = str_replace('final/', '', $qrFile->final_path);
                $pdfDiskToUse = 'final';
            } else {
                Log::error('El documento no tiene file_path ni final_path:', ['qr_id' => $qrFile->qr_id]);
                return response()->json([
                    'success' => false,
                    'message' => 'El documento no tiene un archivo PDF asociado'
                ], 422);
            }

            $requestWidth = (float) $request->input('width');
            $requestHeight = (float) $request->input('height');
            $finalDimension = $requestWidth;
            
            $position = [
                'x' => (float) $request->input('x'),
                'y' => (float) $request->input('y'),
                'width' => $finalDimension,
                'height' => $finalDimension,
            ];

            $validationPath = $pdfDiskToUse === 'final' 
                ? "final/{$pdfPathToUse}" 
                : $pdfPathToUse;
            if ($position['x'] < 0 || $position['y'] < 0 || 
                $position['width'] < 0 || $position['height'] < 0) {
                Log::error('Coordenadas inválidas:', [
                    'pdf_path' => $validationPath,
                    'position' => $position
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'Las coordenadas del QR son inválidas'
                ], 422);
            }

            // Procesar PDF y embebir QR
            // Obtener título del documento para metadatos del PDF
            $documentTitle = $qrFile->original_filename ?: $qrFile->folder_name;
            
            // Pasar qr_id para nueva estructura optimizada de carpetas
            // Pasar título y nombre de carpeta para metadatos del PDF
            $finalPath = $this->pdfProcessor->embedQr(
                $validationPath,
                $qrFile->qr_path,
                $position,
                $pdfDiskToUse,
                $qrFile->qr_id, // Pasar qr_id para nueva estructura
                $documentTitle,  // Título del documento para metadatos
                $qrFile->folder_name // Nombre de carpeta como fallback
            );

            // Actualizar registro PRIMERO (antes de eliminar archivos)
            // Usar transacción para asegurar consistencia
            DB::transaction(function () use ($qrFile, $finalPath, $position) {
                $qrFile->update([
                    'final_path' => $finalPath,
                    'qr_position' => $position,
                    'status' => 'completed',
                ]);
            });
            
            CacheHelper::invalidateDocumentsCache();

            // URL pública del PDF final a través de la API (escalable para producción)
            // Usar helper que respeta el protocolo de la solicitud actual (HTTPS si viene de ngrok)
            $finalUrl = \App\Helpers\UrlHelper::url("/api/files/pdf/{$qrFile->qr_id}", $request);

            return response()->json([
                'success' => true,
                'message' => 'QR embebido exitosamente en el PDF',
                'data' => [
                    'final_pdf_url' => $finalUrl,
                    'status' => 'completed',
                    'qr_position' => $position,
                ]
            ], 200);

        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();
            Log::error('Error al embebir QR: ' . $errorMessage, [
                'qr_id' => $request->input('qr_id'),
                'trace' => $e->getTraceAsString(),
                'error_class' => get_class($e),
                'error_code' => $e->getCode()
            ]);
            
            // Actualizar estado a failed (solo si el documento existe y no hay error de BD)
            if (isset($qrFile) && $qrFile->exists) {
                try {
                    $qrFile->update(['status' => 'failed']);
                    
                    // Invalidar cache de estadísticas cuando falla un documento
                    CacheHelper::invalidateDocumentsCache();
                } catch (\Exception $updateError) {
                    Log::error('Error al actualizar estado a failed: ' . $updateError->getMessage());
                }
            }

            return response()->json([
                'success' => false,
                'message' => $errorMessage, // Mensaje completo sin truncar
                'error_type' => stripos($errorMessage, 'compression') !== false || 
                               stripos($errorMessage, 'not supported by the free parser') !== false ||
                               stripos($errorMessage, 'FPDI') !== false ? 'fpdi_compression' : 'unknown'
            ], 500);
        }
    }

    /**
     * Recibir PDF modificado con pdf-lib desde el frontend (método iLovePDF)
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function embedPdf(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'qr_id' => 'required|string|max:255',
                'pdf' => 'required|file|mimes:pdf|max:512000', // Máximo 500MB para procesar PDF con QR
                'x' => 'required|numeric|min:0',
                'y' => 'required|numeric|min:0',
                'width' => 'required|numeric|min:50|max:300',
                'height' => 'required|numeric|min:50|max:300',
            ], [
                'pdf.required' => 'El archivo PDF es requerido',
                'pdf.file' => 'El PDF debe ser un archivo válido',
                'pdf.mimes' => 'El archivo debe ser un PDF',
                'pdf.max' => 'El archivo PDF no puede exceder 500MB. Tamaño actual: ' . 
                    ($request->hasFile('pdf') ? round($request->file('pdf')->getSize() / 1024 / 1024, 2) . 'MB' : 'N/A'),
            ]);

            if ($validator->fails()) {
                Log::error('Error de validación en embedPdf:', [
                    'request' => $request->except(['pdf']), // No loguear el PDF completo
                    'has_file' => $request->hasFile('pdf'),
                    'file_size' => $request->hasFile('pdf') ? $request->file('pdf')->getSize() : null,
                    'errors' => $validator->errors()->toArray(),
                    'all_keys' => array_keys($request->all())
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'Error de validación: ' . $validator->errors()->first(),
                    'errors' => $validator->errors()
                ], 422);
            }

            // Buscar el archivo QR
            $qrId = $request->input('qr_id');
            
            if (!\App\Helpers\QrIdValidator::isValid($qrId)) {
                return response()->json([
                    'success' => false,
                    'message' => 'ID de documento inválido'
                ], 400);
            }
            
            // Aumentar límites para procesar PDFs grandes (hasta 500MB)
            ini_set('memory_limit', '1024M'); // 1GB para PDFs muy grandes
            set_time_limit(600); // 10 minutos para PDFs grandes
            
            $qrFile = QrFile::withTrashed()->where('qr_id', $qrId)->first();

            if (!$qrFile) {
                return response()->json([
                    'success' => false,
                    'message' => 'Documento no encontrado'
                ], 404);
            }

            // Si el documento está eliminado (soft delete), restaurarlo
            if ($qrFile->trashed()) {
                $qrFile->restore();
            }
            // El frontend envía un Blob como archivo en FormData
            $pdfFile = $request->file('pdf');
            
            if (!$pdfFile || !$pdfFile->isValid()) {
                Log::error('Archivo PDF no válido o no recibido:', [
                    'has_file' => $request->hasFile('pdf'),
                    'file_valid' => $pdfFile ? $pdfFile->isValid() : false,
                    'all_inputs' => array_keys($request->all())
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'El archivo PDF no se recibió correctamente'
                ], 422);
            }
            
            // NUEVA ESTRUCTURA OPTIMIZADA: final/{TIPO}/{YYYYMM}/{qr_id}/documento.pdf
            $documentType = \App\Models\QrFile::extractDocumentType($qrFile->folder_name);
            $monthYear = now()->format('Ym');
            $finalFolder = "{$documentType}/{$monthYear}/{$qrFile->qr_id}";
            Storage::disk('final')->makeDirectory($finalFolder);
            
            // Nombre del archivo: solo el nombre original (sin prefijos)
            $finalFileName = $qrFile->original_filename;
            $finalPath = "final/{$finalFolder}/{$finalFileName}";

            // PROCESAR PDF: Garantizar que solo tenga 1 página
            // Aunque el frontend debería enviar solo 1 página, procesamos el PDF para asegurarlo
            $pdfContent = file_get_contents($pdfFile->getRealPath());
            
            try {
                $pdf = new \setasign\Fpdi\Tcpdf\Fpdi();
                // Intentar abrir el PDF (si está protegido con contraseña, fallará aquí)
                try {
                    $pageCount = $pdf->setSourceFile($pdfFile->getRealPath());
                } catch (\setasign\Fpdi\PdfParser\CrossReference\CrossReferenceException $e) {
                    $errorMsg = $e->getMessage();
                    if (stripos($errorMsg, 'password') !== false || 
                        stripos($errorMsg, 'encrypted') !== false) {
                        Log::error('PDF protegido con contraseña recibido desde frontend:', [
                            'qr_id' => $qrId,
                            'error' => $errorMsg
                        ]);
                        return response()->json([
                            'success' => false,
                            'message' => 'El PDF está protegido con contraseña. Por favor, desbloquee el PDF antes de subirlo.',
                            'error_type' => 'password_protected'
                        ], 422);
                    }
                    throw $e;
                }
                
                
                if ($pageCount > 1) {
                    
                    // Crear un nuevo PDF con solo la primera página
                    $newPdf = new \setasign\Fpdi\Tcpdf\Fpdi();
                    $newPdf->setSourceFile($pdfFile->getRealPath());
                    
                    // Importar solo la primera página
                    $tplId = $newPdf->importPage(1);
                    $size = $newPdf->getTemplateSize($tplId);
                    
                    // Agregar página con las dimensiones correctas
                    $newPdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
                    $newPdf->useTemplate($tplId, 0, 0, $size['width'], $size['height'], true);
                    
                    // Obtener el contenido del nuevo PDF (solo con 1 página)
                    $pdfContent = $newPdf->Output('', 'S'); // 'S' = string output
                    
                }
            } catch (\Exception $e) {
                Log::error('Error al procesar PDF recibido, se usará el PDF original:', [
                    'qr_id' => $qrId,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                // Continuamos con el PDF original
            }

            // Guardar el PDF (ya procesado para tener solo 1 página) en storage
            Storage::disk('final')->put($finalPath, $pdfContent);
            
            // Verificar que el PDF guardado tenga solo 1 página
            try {
                $verifyPdf = new \setasign\Fpdi\Tcpdf\Fpdi();
                $verifyPath = Storage::disk('final')->path($finalPath);
                $verifyPageCount = $verifyPdf->setSourceFile($verifyPath);
                
                if ($verifyPageCount > 1) {
                    Log::error('ERROR CRÍTICO: PDF guardado tiene más de 1 página después del procesamiento', [
                        'qr_id' => $qrId,
                        'page_count' => $verifyPageCount,
                        'final_path' => $finalPath
                    ]);
                }
            } catch (\Exception $e) {
                // No crítico si no se puede verificar
            }

            // Preparar posición
            // CRÍTICO: Forzar que width y height sean iguales usando width como referencia
            // Esto mantiene el tamaño visual original (ej: 125x125, no 131x131)
            $requestWidth = (float) $request->input('width');
            $requestHeight = (float) $request->input('height');
            
            // Usar width como referencia para mantener tamaño visual original
            // Si hay diferencia, usar width (no promedio) para no "crecer" el QR
            $finalDimension = $requestWidth;
            
            
            $position = [
                'x' => (float) $request->input('x'),
                'y' => (float) $request->input('y'),
                'width' => $finalDimension,   // Usar width como referencia
                'height' => $finalDimension,  // Forzar igual a width
            ];

            // Validar que la posición esté dentro del PDF (sin margen - libertad total)
            // Con SAFE_MARGIN = 0, solo validamos que esté dentro del PDF
            // El frontend envía coordenadas en el espacio estándar 595x842
            $SAFE_MARGIN = 0; // 0px de margen = libertad total para colocar el QR
            
            if ($position['x'] < 0 || $position['y'] < 0 || 
                $position['width'] < 0 || $position['height'] < 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Las coordenadas del QR son inválidas'
                ], 422);
            }

            // Actualizar registro en BD
            DB::transaction(function () use ($qrFile, $finalPath, $position) {
                $qrFile->update([
                    'final_path' => $finalPath,
                    'qr_position' => $position,
                    'status' => 'completed',
                ]);
            });
            
            CacheHelper::invalidateDocumentsCache();

            if ($qrFile->file_path && Storage::disk('local')->exists($qrFile->file_path)) {
                try {
                    Storage::disk('local')->delete($qrFile->file_path);
                } catch (\Exception $e) {
                    // No crítico si no se puede eliminar
                }
            }

            $finalUrl = \App\Helpers\UrlHelper::url("/api/files/pdf/{$qrFile->qr_id}", $request);

            return response()->json([
                'success' => true,
                'message' => 'PDF modificado guardado exitosamente (método pdf-lib)',
                'data' => [
                    'final_pdf_url' => $finalUrl,
                    'status' => 'completed',
                    'qr_position' => $position,
                ]
            ], 200);

        } catch (\Exception $e) {
            Log::error('Error al guardar PDF modificado con pdf-lib: ' . $e->getMessage(), [
                'qr_id' => $request->input('qr_id'),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al guardar el PDF: ' . $e->getMessage()
            ], 500);
        }
    }

    // Método extractDocumentType removido - usar QrFile::extractDocumentType() en su lugar
}

