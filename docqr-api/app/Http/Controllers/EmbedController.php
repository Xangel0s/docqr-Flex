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
use Illuminate\Support\Str;

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
                Log::info('Documento restaurado desde soft delete:', ['qr_id' => $qrId]);
            }

            // Verificar que el archivo PDF existe
            // IMPORTANTE: Siempre usar el PDF original para reposicionar el QR
            // Si usamos el PDF final, estaríamos agregando un QR sobre otro QR
            $pdfPathToUse = null;
            $pdfDiskToUse = null;
            
            // PRIORIDAD 1: Usar PDF original si existe físicamente
            if ($qrFile->file_path && Storage::disk('local')->exists($qrFile->file_path)) {
                $pdfPathToUse = $qrFile->file_path;
                $pdfDiskToUse = 'local';
                Log::info('Usando PDF original para reposicionar QR:', [
                    'qr_id' => $qrFile->qr_id,
                    'file_path' => $qrFile->file_path
                ]);
            } 
            // PRIORIDAD 2: Si el original fue eliminado pero existe el final, usar el final como fallback
            // (Esto puede pasar si se eliminó el original para ahorrar espacio)
            elseif ($qrFile->final_path) {
                $pdfPathToUse = str_replace('final/', '', $qrFile->final_path);
                $pdfDiskToUse = 'final';
                Log::warning('PDF original no encontrado, usando PDF final como fallback:', [
                    'qr_id' => $qrFile->qr_id,
                    'final_path' => $qrFile->final_path
                ]);
            } else {
                Log::error('El documento no tiene file_path ni final_path:', ['qr_id' => $qrFile->qr_id]);
                return response()->json([
                    'success' => false,
                    'message' => 'El documento no tiene un archivo PDF asociado'
                ], 422);
            }

            // Preparar posición
            $position = [
                'x' => (float) $request->input('x'),
                'y' => (float) $request->input('y'),
                'width' => (float) $request->input('width'),
                'height' => (float) $request->input('height'),
            ];

            Log::info('Intentando embebir QR:', [
                'qr_id' => $qrFile->qr_id,
                'pdf_path_to_use' => $pdfPathToUse,
                'pdf_disk_to_use' => $pdfDiskToUse,
                'position' => $position
            ]);

            // Validar que la posición esté dentro de los límites
            // Usar el path correcto según si es final_path o file_path
            // NOTA: Con SAFE_MARGIN = 0, solo validamos que esté dentro del PDF (sin margen)
            $validationPath = $pdfDiskToUse === 'final' 
                ? "final/{$pdfPathToUse}" 
                : $pdfPathToUse;
                
            // La validación del margen se hace dentro de PdfProcessorService
            // Aquí solo validamos que las coordenadas sean válidas
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
            // Pasar qr_id para nueva estructura optimizada de carpetas
            $finalPath = $this->pdfProcessor->embedQr(
                $validationPath,
                $qrFile->qr_path,
                $position,
                $pdfDiskToUse,
                $qrFile->qr_id // Pasar qr_id para nueva estructura
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

            // IMPORTANTE: NO eliminar el PDF original inmediatamente
            // El editor necesita el PDF original para reposicionar el QR
            // Solo se eliminará en un proceso de limpieza posterior (ej: después de X días)
            // Comentado para permitir reposicionamiento del QR
            /*
            if ($qrFile->file_path && Storage::disk('local')->exists($qrFile->file_path)) {
                try {
                    Storage::disk('local')->delete($qrFile->file_path);
                    Log::info('PDF original eliminado exitosamente:', ['file_path' => $qrFile->file_path]);
                } catch (\Exception $e) {
                    Log::warning('No se pudo eliminar PDF original (no crítico):', [
                        'file_path' => $qrFile->file_path,
                        'error' => $e->getMessage()
                    ]);
                }
            }
            */
            Log::info('PDF original conservado para permitir reposicionamiento del QR', [
                'qr_id' => $qrFile->qr_id,
                'file_path' => $qrFile->file_path
            ]);

            // URL pública del PDF final a través de la API (escalable para producción)
            $finalUrl = url("/api/files/pdf/{$qrFile->qr_id}");

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
            Log::error('Error al embebir QR: ' . $e->getMessage(), [
                'qr_id' => $request->input('qr_id'),
                'trace' => $e->getTraceAsString()
            ]);
            
            // Actualizar estado a failed (solo si el documento existe y no hay error de BD)
            if (isset($qrFile) && $qrFile->exists) {
                try {
                    $qrFile->update(['status' => 'failed']);
                } catch (\Exception $updateError) {
                    Log::error('Error al actualizar estado a failed: ' . $updateError->getMessage());
                }
            }

            return response()->json([
                'success' => false,
                'message' => 'Error al procesar el PDF: ' . $e->getMessage()
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
            // Validar request
            // Nota: Las coordenadas pueden tener decimales porque vienen del frontend con precisión
            $validator = Validator::make($request->all(), [
                'qr_id' => 'required|string|max:255',
                'pdf' => 'required|file|mimes:pdf|max:10240', // Máximo 10MB
                'x' => 'required|numeric|min:0',
                'y' => 'required|numeric|min:0',
                'width' => 'required|numeric|min:50|max:300',
                'height' => 'required|numeric|min:50|max:300',
            ], [
                'pdf.required' => 'El archivo PDF es requerido',
                'pdf.file' => 'El PDF debe ser un archivo válido',
                'pdf.mimes' => 'El archivo debe ser un PDF',
                'pdf.max' => 'El archivo PDF no puede exceder 10MB',
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
                Log::info('Documento restaurado desde soft delete:', ['qr_id' => $qrId]);
            }

            // Guardar el PDF modificado
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
            $documentType = $this->extractDocumentType($qrFile->folder_name);
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
                $pageCount = $pdf->setSourceFile($pdfFile->getRealPath());
                
                Log::info('PDF recibido para procesamiento:', [
                    'qr_id' => $qrId,
                    'page_count' => $pageCount,
                    'file_size' => strlen($pdfContent)
                ]);
                
                if ($pageCount > 1) {
                    Log::warning('PDF recibido tiene más de una página, se creará uno nuevo con solo la primera', [
                        'qr_id' => $qrId,
                        'page_count' => $pageCount
                    ]);
                    
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
                    
                    Log::info('PDF procesado: creado nuevo PDF con solo 1 página', [
                        'qr_id' => $qrId,
                        'new_page_count' => 1,
                        'new_file_size' => strlen($pdfContent)
                    ]);
                } else {
                    Log::info('PDF recibido ya tiene solo 1 página, se usa directamente', [
                        'qr_id' => $qrId
                    ]);
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
                } else {
                    Log::info('PDF guardado correctamente con 1 página', [
                        'qr_id' => $qrId,
                        'page_count' => $verifyPageCount
                    ]);
                }
            } catch (\Exception $e) {
                Log::warning('No se pudo verificar el número de páginas del PDF guardado', [
                    'qr_id' => $qrId,
                    'error' => $e->getMessage()
                ]);
            }

            // Preparar posición
            $position = [
                'x' => (float) $request->input('x'),
                'y' => (float) $request->input('y'),
                'width' => (float) $request->input('width'),
                'height' => (float) $request->input('height'),
            ];

            // Validar que la posición esté dentro del PDF (sin margen - libertad total)
            // Con SAFE_MARGIN = 0, solo validamos que esté dentro del PDF
            // El frontend envía coordenadas en el espacio estándar 595x842
            $SAFE_MARGIN = 0; // 0px de margen = libertad total para colocar el QR
            
            // Solo validar que las coordenadas sean válidas (no negativas)
            if ($position['x'] < 0 || $position['y'] < 0 || 
                $position['width'] < 0 || $position['height'] < 0) {
                Log::warning('Coordenadas del QR inválidas', [
                    'qr_id' => $qrId,
                    'position' => $position
                ]);
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

            // Eliminar PDF original (solo el archivo físico, no actualizar file_path en BD)
            if ($qrFile->file_path && Storage::disk('local')->exists($qrFile->file_path)) {
                try {
                    Storage::disk('local')->delete($qrFile->file_path);
                    Log::info('PDF original eliminado exitosamente:', ['file_path' => $qrFile->file_path]);
                } catch (\Exception $e) {
                    Log::warning('No se pudo eliminar PDF original (no crítico):', [
                        'file_path' => $qrFile->file_path,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            // URL pública del PDF final
            $finalUrl = url("/api/files/pdf/{$qrFile->qr_id}");

            Log::info('PDF modificado con pdf-lib guardado exitosamente:', [
                'qr_id' => $qrId,
                'final_path' => $finalPath,
                'position' => $position
            ]);

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

    /**
     * Extraer el tipo de documento del folder_name
     * Ejemplo: "CE-12345" -> "CE", "IN-ABC" -> "IN", "SU-XYZ" -> "SU"
     * 
     * @param string $folderName
     * @return string Tipo de documento (CE, IN, SU) o "OTROS" si no coincide
     */
    private function extractDocumentType(string $folderName): string
    {
        // Extraer las primeras letras antes del guion
        $parts = explode('-', $folderName);
        $type = strtoupper(trim($parts[0] ?? ''));
        
        // Validar que sea uno de los tipos permitidos
        $allowedTypes = ['CE', 'IN', 'SU'];
        
        if (in_array($type, $allowedTypes)) {
            return $type;
        }
        
        // Si no coincide, usar "OTROS" como carpeta por defecto
        return 'OTROS';
    }
}

