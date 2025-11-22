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
                // IMPORTANTE: final_path ya NO incluye el prefijo "final/"
                $pdfPathToUse = $qrFile->final_path;
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
            
            // Obtener número de página (por defecto 1)
            $pageNumber = (int) $request->input('page_number', 1);
            
            // Pasar qr_id para nueva estructura optimizada de carpetas
            // Pasar título y nombre de carpeta para metadatos del PDF
            // Pasar número de página para soporte de múltiples páginas
            $finalPath = $this->pdfProcessor->embedQr(
                $validationPath,
                $qrFile->qr_path,
                $position,
                $pdfDiskToUse,
                $qrFile->qr_id, // Pasar qr_id para nueva estructura
                $documentTitle,  // Título del documento para metadatos
                $qrFile->folder_name, // Nombre de carpeta como fallback
                $pageNumber // Número de página donde se colocará el QR
            );

            // Actualizar registro PRIMERO (antes de eliminar archivos)
            // Usar transacción para asegurar consistencia
            // Incluir page_number en la posición guardada
            $positionWithPage = array_merge($position, ['page_number' => $pageNumber]);
            
            DB::transaction(function () use ($qrFile, $finalPath, $positionWithPage) {
                $qrFile->update([
                    'final_path' => $finalPath,
                    'qr_position' => $positionWithPage,
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
            // Log detallado de lo que se recibe (antes de validar)
            Log::info('embedPdf recibido:', [
                'method' => $request->method(),
                'content_type' => $request->header('Content-Type'),
                'has_file_pdf' => $request->hasFile('pdf'),
                'qr_id_input' => $request->input('qr_id'),
                'qr_id_all' => $request->all('qr_id'),
                'all_inputs_keys' => array_keys($request->all()),
                'all_inputs' => $request->except(['pdf']), // No loguear el PDF completo
                'x' => $request->input('x'),
                'y' => $request->input('y'),
                'width' => $request->input('width'),
                'height' => $request->input('height'),
            ]);

            $validator = Validator::make($request->all(), [
                'qr_id' => 'required|string|max:255',
                'pdf' => 'required|file|mimes:pdf,application/pdf,application/x-pdf|max:512000', // Máximo 500MB
                'x' => 'required|numeric|min:0',
                'y' => 'required|numeric|min:0',
                'width' => 'required|numeric|min:50|max:300',
                'height' => 'required|numeric|min:50|max:300',
            ], [
                'qr_id.required' => 'El ID del QR es requerido',
                'pdf.required' => 'El archivo PDF es requerido',
                'pdf.file' => 'El PDF debe ser un archivo válido',
                'pdf.mimes' => 'El archivo debe ser un PDF válido',
                'pdf.max' => 'El archivo PDF no puede exceder 500MB. Tamaño actual: ' . 
                    ($request->hasFile('pdf') ? round($request->file('pdf')->getSize() / 1024 / 1024, 2) . 'MB' : 'N/A'),
                'x.required' => 'La coordenada X es requerida',
                'y.required' => 'La coordenada Y es requerida',
                'width.required' => 'El ancho es requerido',
                'height.required' => 'El alto es requerido',
            ]);

            if ($validator->fails()) {
                Log::error('Error de validación en embedPdf:', [
                    'request' => $request->except(['pdf']), // No loguear el PDF completo
                    'has_file' => $request->hasFile('pdf'),
                    'file_size' => $request->hasFile('pdf') ? $request->file('pdf')->getSize() : null,
                    'errors' => $validator->errors()->toArray(),
                    'all_keys' => array_keys($request->all()),
                    'qr_id_received' => $request->input('qr_id'),
                    'content_type' => $request->header('Content-Type'),
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
            
            if (!$pdfFile) {
                Log::error('Archivo PDF no recibido en embedPdf:', [
                    'has_file' => $request->hasFile('pdf'),
                    'all_inputs' => array_keys($request->all()),
                    'content_type' => $request->header('Content-Type'),
                    'content_length' => $request->header('Content-Length')
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'El archivo PDF no se recibió correctamente. Verifica que el archivo se esté enviando en el FormData.'
                ], 422);
            }
            
            if (!$pdfFile->isValid()) {
                Log::error('Archivo PDF no válido en embedPdf:', [
                    'has_file' => $request->hasFile('pdf'),
                    'file_valid' => $pdfFile->isValid(),
                    'file_size' => $pdfFile->getSize(),
                    'file_mime' => $pdfFile->getMimeType(),
                    'file_error' => $pdfFile->getError(),
                    'all_inputs' => array_keys($request->all())
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'El archivo PDF no es válido. Error: ' . $pdfFile->getErrorMessage()
                ], 422);
            }
            
            // Verificar tamaño del archivo
            $fileSize = $pdfFile->getSize();
            $maxSize = 512000 * 1024; // 500MB en KB
            if ($fileSize > $maxSize) {
                Log::error('PDF excede tamaño máximo:', [
                    'qr_id' => $qrId,
                    'file_size' => $fileSize,
                    'max_size' => $maxSize,
                    'size_mb' => round($fileSize / 1024 / 1024, 2)
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'El archivo PDF excede el tamaño máximo de 500MB. Tamaño actual: ' . round($fileSize / 1024 / 1024, 2) . 'MB'
                ], 422);
            }
            
            // NUEVA ESTRUCTURA OPTIMIZADA: final/{TIPO}/{YYYYMM}/{qr_id}/documento.pdf
            $documentType = \App\Models\QrFile::extractDocumentType($qrFile->folder_name);
            $monthYear = now()->format('Ym');
            $finalFolder = "{$documentType}/{$monthYear}/{$qrFile->qr_id}";
            Storage::disk('final')->makeDirectory($finalFolder);
            
            // Nombre del archivo: solo el nombre original (sin prefijos)
            $finalFileName = $qrFile->original_filename;
            // IMPORTANTE: No incluir "final/" porque Storage::disk('final') ya apunta a storage/app/final/
            $finalPath = "{$finalFolder}/{$finalFileName}";

            // El frontend ya procesa el PDF con pdf-lib y envía solo la primera página con el QR embebido
            // Por lo tanto, podemos usar el PDF directamente sin reprocesarlo
            // Solo verificamos que sea válido y tenga contenido
            $pdfContent = file_get_contents($pdfFile->getRealPath());
            
            try {
                // Verificar que el PDF tenga contenido válido
                if (strlen($pdfContent) < 100) {
                    throw new \Exception('El PDF recibido está vacío o es inválido');
                }
                
                // Verificar header PDF
                if (substr($pdfContent, 0, 4) !== '%PDF') {
                    throw new \Exception('El archivo recibido no es un PDF válido');
                }
                
                // El PDF ya viene procesado por el frontend (pdf-lib)
                // No necesitamos reprocesarlo con FPDI, solo validar que sea un PDF válido
                // Si el frontend lo procesó correctamente, debería tener solo 1 página
                Log::info('PDF procesado por frontend recibido, guardando directamente:', [
                    'qr_id' => $qrId,
                    'file_size' => strlen($pdfContent),
                    'original_filename' => $qrFile->original_filename
                ]);
                
            } catch (\Exception $e) {
                Log::error('Error al validar PDF recibido:', [
                    'qr_id' => $qrId,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'Error al validar el PDF: ' . $e->getMessage(),
                    'error_type' => 'pdf_validation_error'
                ], 422);
            }

            // Guardar el PDF procesado por el frontend directamente
            // El frontend ya garantiza que tiene solo 1 página con el QR embebido
            Log::info('Guardando PDF en disco final:', [
                'qr_id' => $qrId,
                'final_path' => $finalPath,
                'pdf_size' => strlen($pdfContent),
                'disk' => 'final'
            ]);
            
            $saved = Storage::disk('final')->put($finalPath, $pdfContent);
            
            if (!$saved) {
                Log::error('No se pudo guardar el PDF en el disco:', [
                    'qr_id' => $qrId,
                    'final_path' => $finalPath
                ]);
                throw new \Exception('No se pudo guardar el PDF en el servidor');
            }
            
            // Verificar que el archivo se guardó correctamente
            $savedPath = Storage::disk('final')->path($finalPath);
            if (!file_exists($savedPath)) {
                Log::error('El archivo guardado no existe:', [
                    'qr_id' => $qrId,
                    'expected_path' => $savedPath
                ]);
                throw new \Exception('El PDF se guardó pero no se encuentra en el servidor');
            }
            
            $savedSize = filesize($savedPath);
            Log::info('PDF guardado exitosamente:', [
                'qr_id' => $qrId,
                'final_path' => $finalPath,
                'saved_path' => $savedPath,
                'saved_size' => $savedSize,
                'original_size' => strlen($pdfContent),
                'match' => $savedSize === strlen($pdfContent) ? 'SÍ' : 'NO'
            ]);
            
            // Verificación opcional: Intentar verificar con FPDI (puede fallar si tiene compresión no soportada)
            // Si falla, no es crítico porque el PDF ya viene procesado correctamente por el frontend
            try {
                $verifyPdf = new \setasign\Fpdi\Tcpdf\Fpdi();
                $verifyPageCount = $verifyPdf->setSourceFile($savedPath);
                
                Log::info('PDF verificado con FPDI:', [
                    'qr_id' => $qrId,
                    'page_count' => $verifyPageCount,
                    'final_path' => $finalPath
                ]);
            } catch (\Exception $e) {
                // No crítico: El PDF ya viene procesado por el frontend
                // Si FPDI no puede leerlo (compresión no soportada), no importa
                // porque el frontend ya lo procesó correctamente con pdf-lib
                Log::info('No se pudo verificar PDF con FPDI (no crítico, PDF ya procesado por frontend):', [
                    'qr_id' => $qrId,
                    'error' => $e->getMessage()
                ]);
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

            // IMPORTANTE: NO eliminar el PDF original después de guardar
            // El editor necesita el PDF original para seguir editando
            // Solo se eliminará cuando el documento sea eliminado completamente
            // if ($qrFile->file_path && Storage::disk('local')->exists($qrFile->file_path)) {
            //     try {
            //         Storage::disk('local')->delete($qrFile->file_path);
            //     } catch (\Exception $e) {
            //         // No crítico si no se puede eliminar
            //     }
            // }

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

