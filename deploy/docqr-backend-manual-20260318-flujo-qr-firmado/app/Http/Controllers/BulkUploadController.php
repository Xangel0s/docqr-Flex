<?php

namespace App\Http\Controllers;

use App\Models\QrFile;
use App\Services\QrGeneratorService;
use App\Services\PdfSignatureService;
use App\Services\PdfValidationService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use App\Helpers\CacheHelper;

/**
 * Controlador para carga masiva de PDFs (Módulo Inyección Masiva)
 * 
 * Permite crear múltiples documentos tipo IN con inyección rápida de PDFs.
 */
class BulkUploadController extends Controller
{
    protected $qrGenerator;
    protected $pdfSignatureService;
    protected $pdfValidator;

    public function __construct(
        QrGeneratorService $qrGenerator,
        PdfSignatureService $pdfSignatureService,
        PdfValidationService $pdfValidator
    )
    {
        $this->qrGenerator = $qrGenerator;
        $this->pdfSignatureService = $pdfSignatureService;
        $this->pdfValidator = $pdfValidator;
    }

    /**
     * Listar documentos del módulo masivo (tipo IN con fecha_emision)
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = QrFile::withoutTrashed()
                ->whereNotNull('qr_id')
                ->where('qr_id', '!=', '')
                ->where('folder_name', 'like', 'IN-%');

            if ($request->has('search') && $request->search) {
                $search = $request->search;
                $query->where(function($q) use ($search) {
                    $q->where('original_filename', 'like', '%' . $search . '%')
                      ->orWhere('folder_name', 'like', '%' . $search . '%');
                });
            }

            if ($request->has('date_from') && $request->date_from) {
                $query->whereDate('fecha_emision', '>=', $request->date_from);
            }
            if ($request->has('date_to') && $request->date_to) {
                $query->whereDate('fecha_emision', '<=', $request->date_to);
            }

            $sortBy = $request->input('sort_by', 'created_at');
            $sortOrder = $request->input('sort_order', 'desc');
            $allowedSorts = ['created_at', 'folder_name', 'original_filename', 'fecha_emision'];
            if (!in_array($sortBy, $allowedSorts)) {
                $sortBy = 'created_at';
            }
            $query->orderBy($sortBy, $sortOrder);
            if ($sortBy !== 'id') {
                $query->orderBy('id', $sortOrder);
            }

            $perPage = min(max(1, (int) $request->input('per_page', 20)), 100);
            $documents = $query->paginate($perPage)->withQueryString();

            $documents->through(function ($document) {
                return [
                    'id' => $document->id,
                    'qr_id' => $document->qr_id,
                    'folder_name' => $document->folder_name,
                    'original_filename' => $document->original_filename,
                    'fecha_emision' => $document->fecha_emision ? $document->fecha_emision->format('Y-m-d') : null,
                    'file_size' => $document->file_size,
                    'status' => $document->status,
                    'has_pdf' => !empty($document->file_path),
                    'created_at' => optional($document->created_at)->format('Y-m-d H:i:s'),
                    'qr_url' => \App\Helpers\UrlHelper::url("/api/view/{$document->qr_id}", request()),
                    'pdf_url' => !empty($document->qr_id) ? \App\Helpers\UrlHelper::url("/api/files/pdf/{$document->qr_id}", request()) : null,
                    'qr_image_url' => !empty($document->qr_id) ? \App\Helpers\UrlHelper::url("/api/files/qr/{$document->qr_id}", request()) : null,
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $documents->items(),
                'meta' => [
                    'current_page' => $documents->currentPage(),
                    'last_page' => $documents->lastPage(),
                    'per_page' => $documents->perPage(),
                    'total' => $documents->total(),
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Error en BulkUpload index: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Error al cargar documentos masivos'
            ], 500);
        }
    }

    /**
     * Verificar si un código (folder_name) ya existe
     */
    public function checkCode(Request $request): JsonResponse
    {
        try {
            $folderName = $request->input('folder_name', '');
            $exists = QrFile::withoutTrashed()->where('folder_name', $folderName)->exists();

            return response()->json([
                'success' => true,
                'exists' => $exists,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al verificar código'
            ], 500);
        }
    }

    /**
     * Crear un documento individual (fila) con código manual y fecha_emision
     * Sin PDF - el PDF se inyecta después
     */
    public function createRow(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'folder_name' => [
                    'required',
                    'string',
                    'max:100',
                    'regex:/^IN-[A-Za-z0-9ÑñÁÉÍÓÚáéíóúÜü\-]+$/u',
                    'unique:qr_files,folder_name'
                ],
                'fecha_emision' => 'required|date',
            ], [
                'folder_name.required' => 'El código es obligatorio',
                'folder_name.regex' => 'El código debe empezar con IN- seguido del identificador',
                'folder_name.unique' => 'Este código ya existe en el sistema',
                'fecha_emision.required' => 'La fecha de emisión es obligatoria',
                'fecha_emision.date' => 'Formato de fecha inválido',
            ]);

            do {
                $qrId = Str::random(12);
            } while (QrFile::withoutTrashed()->where('qr_id', $qrId)->exists());

            $qrUrl = \App\Helpers\UrlHelper::url("/api/view/{$qrId}", $request);
            $qrPath = $this->qrGenerator->generate($qrUrl, $qrId);

            $document = QrFile::create([
                'qr_id' => $qrId,
                'folder_name' => $request->input('folder_name'),
                'fecha_emision' => $request->input('fecha_emision'),
                'qr_path' => $qrPath,
                'status' => 'uploaded',
                'file_path' => null,
                'original_filename' => null,
                'file_size' => null,
                'scan_count' => 0,
            ]);

            CacheHelper::invalidateDocumentsCache();

            return response()->json([
                'success' => true,
                'message' => 'Documento creado exitosamente',
                'data' => [
                    'id' => $document->id,
                    'qr_id' => $qrId,
                    'folder_name' => $document->folder_name,
                    'fecha_emision' => $document->fecha_emision->format('Y-m-d'),
                    'has_pdf' => false,
                    'qr_url' => $qrUrl,
                    'qr_image_url' => \App\Helpers\UrlHelper::url("/api/files/qr/{$qrId}", $request),
                ]
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error de validación',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error al crear fila masiva: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Error al crear documento: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Carga masiva: recibir múltiples PDFs y crear filas automáticamente
     * Genera códigos IN- automáticos y crea documentos por cada PDF
     */
    public function bulkUpload(Request $request): JsonResponse
    {
        try {
            ini_set('memory_limit', '1024M');
            set_time_limit(600);

            $request->validate([
                'files' => 'required|array|min:1|max:50',
                'files.*' => 'required|file|max:512000',
                'fecha_emision' => 'required|date',
            ], [
                'files.required' => 'Debes seleccionar al menos un archivo PDF',
                'files.min' => 'Debes seleccionar al menos un archivo PDF',
                'files.max' => 'Máximo 50 archivos por carga',
                'fecha_emision.required' => 'La fecha de emisión es obligatoria',
            ]);

            $files = $request->file('files');
            $fechaEmision = $request->input('fecha_emision');
            $created = [];
            $errors = [];

            DB::beginTransaction();

            foreach ($files as $index => $file) {
                try {
                    $originalName = $file->getClientOriginalName();
                    $hasPdfExtension = str_ends_with(strtolower($originalName), '.pdf');

                    if (!$hasPdfExtension) {
                        $errors[] = [
                            'file' => $originalName,
                            'error' => 'El archivo no tiene extensión .pdf'
                        ];
                        continue;
                    }

                    // Validar que es un PDF real
                    $handle = fopen($file->getRealPath(), 'rb');
                    $header = fread($handle, 4);
                    fclose($handle);
                    if ($header !== '%PDF') {
                        $errors[] = [
                            'file' => $originalName,
                            'error' => 'El archivo no es un PDF válido'
                        ];
                        continue;
                    }

                    // Generar código único IN-XXXXX
                    $folderName = $this->generateUniqueCode();

                    // Generar QR ID único
                    do {
                        $qrId = Str::random(12);
                    } while (QrFile::withoutTrashed()->where('qr_id', $qrId)->exists());

                    $qrUrl = \App\Helpers\UrlHelper::url("/api/view/{$qrId}", $request);
                    $qrPath = $this->qrGenerator->generate($qrUrl, $qrId);

                    // Almacenar PDF
                    $yearMonth = now()->format('Y-m');
                    $storageFolder = "uploads/IN/{$yearMonth}/{$qrId}";
                    Storage::disk('local')->makeDirectory($storageFolder, true);

                    $safeFilename = $this->sanitizeFilename($originalName);
                    $storedPath = Storage::disk('local')->putFileAs(
                        $storageFolder,
                        $file,
                        $safeFilename
                    );

                    if (!$storedPath) {
                        $errors[] = [
                            'file' => $originalName,
                            'error' => 'Error al guardar el archivo'
                        ];
                        continue;
                    }

                    $document = QrFile::create([
                        'qr_id' => $qrId,
                        'folder_name' => $folderName,
                        'fecha_emision' => $fechaEmision,
                        'original_filename' => $originalName,
                        'file_path' => $storedPath,
                        'file_size' => $file->getSize(),
                        'qr_path' => $qrPath,
                        'status' => 'uploaded',
                        'scan_count' => 0,
                    ]);

                    $created[] = [
                        'id' => $document->id,
                        'qr_id' => $qrId,
                        'folder_name' => $folderName,
                        'original_filename' => $originalName,
                        'fecha_emision' => $fechaEmision,
                        'file_size' => $file->getSize(),
                        'has_pdf' => true,
                    ];

                } catch (\Exception $e) {
                    $errors[] = [
                        'file' => $file->getClientOriginalName() ?? "archivo_{$index}",
                        'error' => $e->getMessage()
                    ];
                }
            }

            DB::commit();
            CacheHelper::invalidateDocumentsCache();

            $totalCreated = count($created);
            $totalErrors = count($errors);

            return response()->json([
                'success' => true,
                'message' => "{$totalCreated} documento(s) creado(s) exitosamente" .
                    ($totalErrors > 0 ? ". {$totalErrors} error(es)." : '.'),
                'data' => [
                    'created' => $created,
                    'errors' => $errors,
                    'total_created' => $totalCreated,
                    'total_errors' => $totalErrors,
                ]
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error de validación',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error en carga masiva: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Error en la carga masiva: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Inyectar PDF a un documento existente (individual, desde la tabla)
     */
    public function injectPdf(string $qrId, Request $request): JsonResponse
    {
        try {
            ini_set('memory_limit', '1024M');
            set_time_limit(300);

            if (!\App\Helpers\QrIdValidator::isValid($qrId)) {
                return response()->json([
                    'success' => false,
                    'message' => 'ID de documento inválido'
                ], 400);
            }

            if (!$request->hasFile('file')) {
                return response()->json([
                    'success' => false,
                    'message' => 'No se recibió ningún archivo PDF'
                ], 422);
            }

            $file = $request->file('file');
            $originalName = $file->getClientOriginalName();

            if (!str_ends_with(strtolower($originalName), '.pdf')) {
                return response()->json([
                    'success' => false,
                    'message' => 'El archivo debe ser un PDF'
                ], 422);
            }

            // Verificar header PDF
            $handle = fopen($file->getRealPath(), 'rb');
            $header = fread($handle, 4);
            fclose($handle);
            if ($header !== '%PDF') {
                return response()->json([
                    'success' => false,
                    'message' => 'El archivo no es un PDF válido'
                ], 422);
            }

            $document = QrFile::where('qr_id', $qrId)->firstOrFail();

            // Almacenar PDF
            $documentType = QrFile::extractDocumentType($document->folder_name);
            $yearMonth = $document->created_at->format('Y-m');
            $storageFolder = "uploads/{$documentType}/{$yearMonth}/{$qrId}";
            Storage::disk('local')->makeDirectory($storageFolder, true);

            $safeFilename = $this->sanitizeFilename($originalName);
            $storedPath = Storage::disk('local')->putFileAs(
                $storageFolder,
                $file,
                $safeFilename
            );

            if (!$storedPath) {
                return response()->json([
                    'success' => false,
                    'message' => 'Error al guardar el archivo PDF'
                ], 500);
            }

            // Eliminar archivo anterior si existía
            if ($document->file_path && Storage::disk('local')->exists($document->file_path) && $document->file_path !== $storedPath) {
                Storage::disk('local')->delete($document->file_path);
            }

            $document->update([
                'file_path' => $storedPath,
                'original_filename' => $originalName,
                'file_size' => $file->getSize(),
                'final_path' => null,
                'qr_position' => null,
                'status' => 'uploaded',
            ]);

            CacheHelper::invalidateDocumentsCache();

            return response()->json([
                'success' => true,
                'message' => 'PDF inyectado exitosamente',
                'data' => [
                    'id' => $document->id,
                    'qr_id' => $qrId,
                    'original_filename' => $originalName,
                    'file_size' => $file->getSize(),
                    'has_pdf' => true,
                ]
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Documento no encontrado'
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error al inyectar PDF: ' . $e->getMessage(), [
                'qr_id' => $qrId,
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Error al inyectar PDF: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Actualizar fecha de emisión de un documento
     */
    public function updateFechaEmision(string $qrId, Request $request): JsonResponse
    {
        try {
            $request->validate([
                'fecha_emision' => 'required|date',
            ]);

            $document = QrFile::where('qr_id', $qrId)->firstOrFail();
            $document->update([
                'fecha_emision' => $request->input('fecha_emision'),
            ]);

            CacheHelper::invalidateDocumentsCache();

            return response()->json([
                'success' => true,
                'message' => 'Fecha de emisión actualizada',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar fecha: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Generar un código IN- único automáticamente
     * Formato: IN-N-XXX-YY-COXX donde XXX es secuencial
     */
    private function generateUniqueCode(): string
    {
        $year = date('y'); // 26
        $attempts = 0;
        $maxAttempts = 100;

        do {
            // Obtener el último código IN- para generar el siguiente secuencial
            $lastDoc = QrFile::withoutTrashed()
                ->where('folder_name', 'like', "IN-AUTO-%-{$year}")
                ->orderByRaw('CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(folder_name, "-", 3), "-", -1) AS UNSIGNED) DESC')
                ->first();

            if ($lastDoc) {
                // Extraer número secuencial del último código
                $parts = explode('-', $lastDoc->folder_name);
                $seq = isset($parts[2]) ? ((int) $parts[2] + 1) : 1;
            } else {
                $seq = 1;
            }

            $folderName = "IN-AUTO-" . str_pad($seq + $attempts, 4, '0', STR_PAD_LEFT) . "-{$year}";
            $exists = QrFile::withoutTrashed()->where('folder_name', $folderName)->exists();
            $attempts++;
        } while ($exists && $attempts < $maxAttempts);

        if ($attempts >= $maxAttempts) {
            // Fallback: usar timestamp
            $folderName = "IN-AUTO-" . time() . "-{$year}";
        }

        return $folderName;
    }

    /**
     * Sanitizar nombre de archivo
     */
    private function sanitizeFilename(string $filename): string
    {
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $name = pathinfo($filename, PATHINFO_FILENAME);
        $name = preg_replace('/[^a-zA-Z0-9_\-\.\s]/', '', $name);
        $name = preg_replace('/\s+/', '_', trim($name));
        $maxLength = 200 - strlen($extension) - 1;
        if (strlen($name) > $maxLength) {
            $name = substr($name, 0, $maxLength);
        }
        if (empty($name)) {
            $name = 'document_' . time();
        }
        return $name . '.' . $extension;
    }
}
