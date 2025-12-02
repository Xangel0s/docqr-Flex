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
            // Validar que el archivo existe primero
            if (!$request->hasFile('file')) {
                return response()->json([
                    'success' => false,
                    'message' => 'No se recibió ningún archivo. Por favor, selecciona un archivo PDF.',
                    'errors' => ['file' => ['El archivo es requerido']]
                ], 422);
            }

            $file = $request->file('file');
            $folderName = $request->input('folder_name');
            $fileSize = $file->getSize();
            $mimeType = $file->getMimeType();
            $originalName = $file->getClientOriginalName();
            $hasPdfExtension = str_ends_with(strtolower($originalName), '.pdf');

            // Log para debugging
            Log::info('Intento de upload:', [
                'file_name' => $originalName,
                'file_size' => $fileSize,
                'file_size_mb' => round($fileSize / 1024 / 1024, 2),
                'mime_type' => $mimeType,
                'has_pdf_extension' => $hasPdfExtension,
                'is_valid' => $file->isValid(),
                'folder_name' => $folderName
            ]);

            // Validar tamaño antes de la validación de Laravel
            $maxSizeKB = 512000; // 500MB en KB
            if ($fileSize > $maxSizeKB * 1024) {
                return response()->json([
                    'success' => false,
                    'message' => "El archivo PDF es demasiado grande. Tamaño máximo: 500MB. Tamaño actual: " . round($fileSize / 1024 / 1024, 2) . "MB",
                    'errors' => ['file' => ['El archivo excede el tamaño máximo permitido']]
                ], 422);
            }

            // Validar extensión
            if (!$hasPdfExtension) {
                return response()->json([
                    'success' => false,
                    'message' => 'El archivo debe tener extensión .pdf',
                    'errors' => ['file' => ['El archivo debe ser un PDF']]
                ], 422);
            }

            // Validar MIME type o header del archivo
            $allowedMimes = ['application/pdf', 'application/x-pdf', 'application/octet-stream'];
            if (!in_array($mimeType, $allowedMimes)) {
                // Verificar header como último recurso
                $handle = fopen($file->getRealPath(), 'rb');
                $header = fread($handle, 4);
                fclose($handle);
                
                if ($header !== '%PDF') {
                    return response()->json([
                        'success' => false,
                        'message' => "El archivo no es un PDF válido. Tipo MIME detectado: {$mimeType}",
                        'errors' => ['file' => ['El archivo debe ser un PDF válido']]
                    ], 422);
                }
            }

            // Validar folder_name con validación de unicidad
            $request->validate([
                'folder_name' => [
                    'required',
                    'string',
                    'max:100',
                    'regex:/^(CE|IN|SU)-[A-Za-z0-9ÑñÁÉÍÓÚáéíóúÜü\-]+$/u',
                    'unique:qr_files,folder_name'
                ]
            ], [
                'folder_name.required' => 'El nombre de carpeta es requerido',
                'folder_name.regex' => 'El formato debe ser: TIPO-CODIGO (ej: CE-12345, IN-ABC, SU-XYZ). Solo se permiten tipos: CE, IN, SU.',
                'folder_name.unique' => 'Este código ya existe en el sistema. Por favor elige otro nombre único.',
                'folder_name.max' => 'El nombre de carpeta no puede exceder 100 caracteres'
            ]);

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
            
            // NOTA: Ya no validamos el número de páginas porque el sistema ahora soporta múltiples páginas
            // El usuario puede subir PDFs con cualquier número de páginas y colocar el QR en la página que desee

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