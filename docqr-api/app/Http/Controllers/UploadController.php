<?php

namespace App\Http\Controllers;

use App\Models\QrFile;
use App\Services\QrGeneratorService;
use App\Services\PdfValidationService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use App\Helpers\CacheHelper;

/**
 * Controlador para subir PDFs y generar códigos QR
 */
class UploadController extends Controller
{
    protected $qrGenerator;
    protected $pdfValidator;

    public function __construct(QrGeneratorService $qrGenerator, PdfValidationService $pdfValidator)
    {
        $this->qrGenerator = $qrGenerator;
        $this->pdfValidator = $pdfValidator;
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
            $validator = Validator::make($request->all(), [
                'file' => 'required|file|mimes:pdf|max:512000', // Máximo 500MB para drag and drop
                'folder_name' => 'required|string|max:100',
            ], [
                'file.max' => 'El archivo PDF no puede exceder 500MB. Tamaño actual: ' . 
                    ($request->hasFile('file') ? round($request->file('file')->getSize() / 1024 / 1024, 2) . 'MB' : 'N/A')
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Error de validación',
                    'errors' => $validator->errors()
                ], 422);
            }

            $file = $request->file('file');
            $folderName = $request->input('folder_name');

            $integrityCheck = $this->pdfValidator->validatePdfIntegrity($file);
            if (!$integrityCheck['valid']) {
                Log::error('Error de integridad al subir PDF:', [
                    'message' => $integrityCheck['message'],
                    'file_name' => $file->getClientOriginalName(),
                    'file_size' => $file->getSize(),
                    'error' => $integrityCheck['error'] ?? 'N/A'
                ]);
                
                return response()->json([
                    'success' => false,
                    'message' => $integrityCheck['message']
                ], 422);
            }
            
            // Log de advertencia si se saltó validación FPDI
            if (isset($integrityCheck['skip_fpdi_validation']) && $integrityCheck['skip_fpdi_validation']) {
                Log::info('PDF validado con validación básica (FPDI saltado):', [
                    'file_name' => $file->getClientOriginalName(),
                    'file_size' => $file->getSize(),
                    'warning' => $integrityCheck['warning'] ?? 'N/A'
                ]);
            }
            
            // Solo validar número de páginas para PDFs pequeños (drag and drop requiere 1 página)
            // Para PDFs grandes, saltar esta validación
            if (!isset($integrityCheck['skip_fpdi_validation']) || !$integrityCheck['skip_fpdi_validation']) {
                $pdfInfo = $this->pdfValidator->validatePdfPages($file);
                if (!$pdfInfo['valid']) {
                    return response()->json([
                        'success' => false,
                        'message' => $pdfInfo['message']
                    ], 422);
                }
            }

            $qrId = $this->generateUniqueQrId();
            $documentType = \App\Models\QrFile::extractDocumentType($folderName);
            $monthYear = now()->format('Ym');
            $storageFolder = "uploads/{$documentType}/{$monthYear}/{$qrId}";
            Storage::disk('local')->makeDirectory($storageFolder);

            $originalFilename = $file->getClientOriginalName();
            $filename = $originalFilename;
            $filePath = $file->storeAs($storageFolder, $filename, 'local');
            $fileSize = $file->getSize();

            $qrUrl = \App\Helpers\UrlHelper::url("/api/view/{$qrId}", $request);

            try {
                $qrPath = $this->qrGenerator->generate($qrUrl, $qrId);
            } catch (\Exception $e) {
                Storage::disk('local')->delete($filePath);
                Log::error('Error al generar QR: ' . $e->getMessage());
                return response()->json([
                    'success' => false,
                    'message' => 'Error al generar código QR: ' . $e->getMessage()
                ], 500);
            }

            $qrFile = QrFile::create([
                'qr_id' => $qrId,
                'folder_name' => $folderName,
                'original_filename' => $originalFilename,
                'file_path' => $filePath,
                'qr_path' => $qrPath,
                'file_size' => $fileSize,
                'status' => 'uploaded',
                'scan_count' => 0,
            ]);

            CacheHelper::invalidateDocumentsCache();
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
            $qrId = Str::random(32);
            $attempts++;
            
            $exists = QrFile::where('qr_id', $qrId)->exists();
            
            if ($attempts >= $maxAttempts) {
                Log::error("No se pudo generar un QR ID único después de {$maxAttempts} intentos");
                throw new \Exception("Error al generar ID único para el QR. Intenta nuevamente.");
            }
            
        } while ($exists);
        
        
        return $qrId;
    }
}

