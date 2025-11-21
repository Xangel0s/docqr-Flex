<?php

namespace App\Http\Controllers;

use App\Models\QrFile;
use App\Services\QrGeneratorService;
use App\Services\PdfValidationService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use App\Helpers\CacheHelper;

/**
 * Controlador para gestión de documentos
 */
class DocumentController extends Controller
{
    protected $qrGenerator;
    protected $pdfValidator;

    public function __construct(QrGeneratorService $qrGenerator, PdfValidationService $pdfValidator)
    {
        $this->qrGenerator = $qrGenerator;
        $this->pdfValidator = $pdfValidator;
    }
    /**
     * Listar documentos con filtros y paginación
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        try {
            // CACHE DESHABILITADO: Para ver cambios en tiempo real
            // Si necesitas reactivar el cache, descomenta las siguientes líneas:
            // $cacheKey = 'documents_list_' . md5(json_encode($request->all()));
            // if (!$request->has('search') && !$request->has('date_from') && !$request->has('date_to')) {
            //     $cached = Cache::get($cacheKey);
            //     if ($cached !== null) {
            //         return response()->json($cached, 200);
            //     }
            // }
            
            // Seleccionar solo campos necesarios para mejorar rendimiento
            // CRÍTICO: Excluir explícitamente documentos eliminados (soft delete)
            // El modelo QrFile usa SoftDeletes, pero asegurémonos de excluirlos explícitamente
            $query = QrFile::withoutTrashed()->select([
                'id', 'qr_id', 'folder_name', 'original_filename', 'file_size',
                'qr_position', 'status', 'scan_count', 'last_scanned_at',
                'created_at', 'updated_at', 'final_path'
            ]);

            if ($request->has('folder') && $request->folder) {
                $query->where('folder_name', $request->folder);
            }

            if ($request->has('type') && $request->type && $request->type !== 'all') {
                $query->where('folder_name', 'like', $request->type . '-%');
            }

            if ($request->has('status') && $request->status && $request->status !== 'all') {
                $query->where('status', $request->status);
            }

            if ($request->has('date_from') && $request->date_from) {
                $query->whereDate('created_at', '>=', $request->date_from);
            }
            if ($request->has('date_to') && $request->date_to) {
                $query->whereDate('created_at', '<=', $request->date_to);
            }

            if ($request->has('scans_filter') && $request->scans_filter && $request->scans_filter !== 'all') {
                switch ($request->scans_filter) {
                    case 'none':
                        $query->where('scan_count', 0);
                        break;
                    case 'with_scans':
                        $query->where('scan_count', '>', 0);
                        break;
                }
            }

            if ($request->has('search') && $request->search) {
                $search = $request->search;
                $query->where(function($q) use ($search) {
                    $q->where('original_filename', 'like', '%' . $search . '%')
                      ->orWhere('folder_name', 'like', '%' . $search . '%');
                });
            }

            $sortBy = $request->input('sort_by', 'created_at');
            $sortOrder = $request->input('sort_order', 'desc');
            
            $allowedSorts = ['created_at', 'original_filename', 'scan_count', 'folder_name'];
            if (!in_array($sortBy, $allowedSorts)) {
                $sortBy = 'created_at';
            }
            
            $query->orderBy($sortBy, $sortOrder);

            $perPage = min($request->input('per_page', 15), 100); // Limitar máximo a 100
            $documents = $query->paginate($perPage);

            $transformedDocuments = $documents->map(function ($document) {
                return [
                    'id' => $document->id,
                    'qr_id' => $document->qr_id,
                    'folder_name' => $document->folder_name,
                    'original_filename' => $document->original_filename,
                    'file_size' => $document->file_size,
                    'qr_position' => $document->qr_position,
                    'status' => $document->status,
                    'scan_count' => $document->scan_count,
                    'last_scanned_at' => $document->last_scanned_at?->format('Y-m-d H:i:s'),
                    'created_at' => $document->created_at?->format('Y-m-d H:i:s'),
                    'updated_at' => $document->updated_at?->format('Y-m-d H:i:s'),
                    'qr_url' => \App\Helpers\UrlHelper::url("/api/view/{$document->qr_id}", request()),
                    'pdf_url' => \App\Helpers\UrlHelper::url("/api/files/pdf/{$document->qr_id}", request()),
                    'pdf_original_url' => \App\Helpers\UrlHelper::url("/api/files/pdf-original/{$document->qr_id}", request()),
                    'qr_image_url' => \App\Helpers\UrlHelper::url("/api/files/qr/{$document->qr_id}", request()),
                    'final_pdf_url' => $document->final_path 
                        ? \App\Helpers\UrlHelper::url("/api/files/pdf/{$document->qr_id}", request())
                        : null,
                ];
            });

            $response = [
                'success' => true,
                'data' => $transformedDocuments->values()->all(),
                'meta' => [
                    'current_page' => $documents->currentPage(),
                    'last_page' => $documents->lastPage(),
                    'per_page' => $documents->perPage(),
                    'total' => $documents->total(),
                ]
            ];
            
            // CACHE DESHABILITADO para tiempo real
            // if (!$request->has('search') && !$request->has('date_from') && !$request->has('date_to')) {
            //     Cache::put($cacheKey, $response, 300);
            // }

            // Headers para evitar cache del navegador y forzar datos frescos
            return response()->json($response, 200)
                ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
                ->header('Pragma', 'no-cache')
                ->header('Expires', '0')
                ->header('X-Content-Type-Options', 'nosniff');

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener documentos: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener un documento específico
     * 
     * @param int $id
     * @return JsonResponse
     */
    public function show(int $id): JsonResponse
    {
        try {
            $document = QrFile::findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $document->id,
                    'qr_id' => $document->qr_id,
                    'folder_name' => $document->folder_name,
                    'original_filename' => $document->original_filename,
                    'file_size' => $document->file_size,
                    'qr_position' => $document->qr_position,
                    'status' => $document->status,
                    'scan_count' => $document->scan_count,
                    'last_scanned_at' => $document->last_scanned_at,
                    'created_at' => $document->created_at,
                    'qr_url' => \App\Helpers\UrlHelper::url("/api/view/{$document->qr_id}", request()),
                    'pdf_url' => \App\Helpers\UrlHelper::url("/api/files/pdf/{$document->qr_id}", request()), // PDF final (si existe) o original
                    'pdf_original_url' => \App\Helpers\UrlHelper::url("/api/files/pdf-original/{$document->qr_id}", request()), // Siempre PDF original (para editor)
                    'qr_image_url' => \App\Helpers\UrlHelper::url("/api/files/qr/{$document->qr_id}", request()),
                    'final_pdf_url' => $document->final_path 
                        ? \App\Helpers\UrlHelper::url("/api/files/pdf/{$document->qr_id}", request())
                        : null,
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Documento no encontrado'
            ], 404);
        }
    }

    /**
     * Obtener un documento por qr_id
     * 
     * @param string $qrId
     * @return JsonResponse
     */
    public function showByQrId(string $qrId): JsonResponse
    {
        try {
            if (!\App\Helpers\QrIdValidator::isValid($qrId)) {
                return response()->json([
                    'success' => false,
                    'message' => 'ID de documento inválido'
                ], 400);
            }

            // CACHE DESHABILITADO: Para ver cambios en tiempo real
            $document = QrFile::withoutTrashed()->select([
                'id', 'qr_id', 'folder_name', 'original_filename', 'file_size',
                'qr_position', 'status', 'scan_count', 'last_scanned_at',
                'created_at', 'updated_at', 'final_path', 'file_path', 'qr_path'
            ])->where('qr_id', $qrId)->firstOrFail();

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $document->id,
                    'qr_id' => $document->qr_id,
                    'folder_name' => $document->folder_name,
                    'original_filename' => $document->original_filename,
                    'file_size' => $document->file_size,
                    'qr_position' => $document->qr_position,
                    'status' => $document->status,
                    'scan_count' => $document->scan_count,
                    'last_scanned_at' => $document->last_scanned_at,
                    'created_at' => $document->created_at,
                    'qr_url' => \App\Helpers\UrlHelper::url("/api/view/{$document->qr_id}", request()),
                    'pdf_url' => \App\Helpers\UrlHelper::url("/api/files/pdf/{$document->qr_id}", request()), // PDF final (si existe) o original
                    'pdf_original_url' => \App\Helpers\UrlHelper::url("/api/files/pdf-original/{$document->qr_id}", request()), // Siempre PDF original (para editor)
                    'qr_image_url' => \App\Helpers\UrlHelper::url("/api/files/qr/{$document->qr_id}", request()),
                    'final_pdf_url' => $document->final_path 
                        ? \App\Helpers\UrlHelper::url("/api/files/pdf/{$document->qr_id}", request())
                        : null,
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Documento no encontrado'
            ], 404);
        }
    }

    /**
     * Actualizar nombre de carpeta de un documento
     * 
     * @param string $qrId
     * @param Request $request
     * @return JsonResponse
     */
    public function updateFolderName(string $qrId, Request $request): JsonResponse
    {
        try {
            if (!\App\Helpers\QrIdValidator::isValid($qrId)) {
                return response()->json([
                    'success' => false,
                    'message' => 'ID de documento inválido'
                ], 400);
            }
            
            $document = QrFile::where('qr_id', $qrId)->first();
            
            if (!$document) {
                return response()->json([
                    'success' => false,
                    'message' => 'Documento no encontrado'
                ], 404);
            }
            
            $request->validate([
                'folder_name' => [
                    'required', 
                    'string', 
                    'max:100', 
                    'regex:/^(CE|IN|SU)-[A-Za-z0-9ÑñÁÉÍÓÚáéíóúÜü\-]+$/u',
                    'unique:qr_files,folder_name,' . $document->id
                ]
            ], [
                'folder_name.required' => 'El nombre de carpeta es obligatorio',
                'folder_name.regex' => 'El formato debe ser: TIPO-CODIGO (ej: CE-12345, IN-ABC, SU-XYZ). Solo se permiten tipos: CE, IN, SU. Se permiten caracteres en español (Ñ, ñ, acentos).',
                'folder_name.unique' => 'Este código ya existe en el sistema. Por favor elige otro nombre único.'
            ]);
            
            $oldFolderName = $document->folder_name;
            $document->folder_name = $request->folder_name;
            $document->save();
            CacheHelper::invalidateDocumentsCache();


            return response()->json([
                'success' => true,
                'message' => 'Nombre de carpeta actualizado exitosamente',
                'data' => [
                    'id' => $document->id,
                    'qr_id' => $document->qr_id,
                    'folder_name' => $document->folder_name,
                    'original_filename' => $document->original_filename,
                ]
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error('Error de validación al actualizar nombre de carpeta:', [
                'qr_id' => $qrId,
                'errors' => $e->errors()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Error de validación',
                'errors' => $e->errors()
            ], 422);
        } catch (ModelNotFoundException $e) {
            Log::error('Documento no encontrado al actualizar nombre de carpeta:', [
                'qr_id' => $qrId,
                'message' => $e->getMessage()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Documento no encontrado'
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error inesperado al actualizar nombre de carpeta:', [
                'qr_id' => $qrId,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar el nombre de carpeta. Por favor, intente nuevamente.'
            ], 500);
        }
    }

    /**
     * Eliminar un documento
     * 
     * @param int $id
     * @return JsonResponse
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            // Buscar documento (incluyendo eliminados con soft delete)
            $document = QrFile::withTrashed()->find($id);

            if (!$document) {
                return response()->json([
                    'success' => true,
                    'message' => 'El documento ya fue eliminado anteriormente'
                ], 200);
            }

            // Invalidar caché ANTES de eliminar para respuesta inmediata
            CacheHelper::invalidateDocumentsCache();
            
            // Eliminar de la BD primero para respuesta inmediata
            // Los archivos se eliminan en background para no bloquear la respuesta
            $document->forceDelete();
            
            // Eliminar archivos físicos en background (no bloquea la respuesta)
            // Esto permite que la eliminación sea inmediata en la UI
            try {
                // Eliminar archivos en paralelo (no bloqueante)
                $filesToDelete = [];
                
                if ($document->file_path && Storage::disk('local')->exists($document->file_path)) {
                    $filesToDelete[] = ['disk' => 'local', 'path' => $document->file_path];
                    $fileDir = dirname($document->file_path);
                    if ($fileDir) {
                        $filesToDelete[] = ['disk' => 'local', 'path' => $fileDir, 'is_dir' => true];
                    }
                }
                
                if ($document->qr_path) {
                    $qrFilename = basename($document->qr_path);
                    if (Storage::disk('qrcodes')->exists($qrFilename)) {
                        $filesToDelete[] = ['disk' => 'qrcodes', 'path' => $qrFilename];
                    }
                }
                
                if ($document->final_path) {
                    $finalPath = str_replace('final/', '', $document->final_path);
                    if (Storage::disk('final')->exists($finalPath)) {
                        $filesToDelete[] = ['disk' => 'final', 'path' => $finalPath];
                        $finalDir = dirname($finalPath);
                        if ($finalDir) {
                            $filesToDelete[] = ['disk' => 'final', 'path' => $finalDir, 'is_dir' => true];
                        }
                    }
                }
                
                // Eliminar archivos (no bloqueante - si falla, se loguea pero no afecta la respuesta)
                foreach ($filesToDelete as $fileInfo) {
                    try {
                        $disk = Storage::disk($fileInfo['disk']);
                        if (isset($fileInfo['is_dir']) && $fileInfo['is_dir']) {
                            // Verificar si el directorio está vacío antes de eliminarlo
                            if ($disk->exists($fileInfo['path'])) {
                                $files = $disk->files($fileInfo['path']);
                                if (empty($files)) {
                                    $disk->deleteDirectory($fileInfo['path']);
                                }
                            }
                        } else {
                            $disk->delete($fileInfo['path']);
                        }
                    } catch (\Exception $e) {
                        // Log pero no fallar - el documento ya fue eliminado de la BD
                        Log::warning('Error al eliminar archivo físico (no crítico): ' . $e->getMessage(), [
                            'disk' => $fileInfo['disk'],
                            'path' => $fileInfo['path']
                        ]);
                    }
                }
            } catch (\Exception $e) {
                // No crítico - el documento ya fue eliminado de la BD
                Log::warning('Error al eliminar archivos físicos (no crítico): ' . $e->getMessage());
            }

            return response()->json([
                'success' => true,
                'message' => 'Documento eliminado completamente de la base de datos'
            ], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => true,
                'message' => 'El documento ya fue eliminado anteriormente'
            ], 200);
        } catch (\Exception $e) {
            Log::error('Error al eliminar documento: ' . $e->getMessage(), [
                'id' => $id,
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Error al eliminar documento: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener estadísticas de documentos
     * 
     * @return JsonResponse
     */
    public function stats(): JsonResponse
    {
        try {
            // CACHE DESHABILITADO: Para ver estadísticas en tiempo real
            // Optimizar queries usando índices y select específicos
            // CRÍTICO: Excluir explícitamente documentos eliminados (soft delete)
            $totalDocuments = QrFile::withoutTrashed()->count();
            $totalScans = QrFile::withoutTrashed()->sum('scan_count') ?? 0;
            
            // Usar índices compuestos para mejor rendimiento
            $completedDocuments = QrFile::withoutTrashed()->where('status', 'completed')->count();
            $pendingDocuments = QrFile::withoutTrashed()->where('status', 'uploaded')->count();
            
            // Usar índice de last_scanned_at
            $scansLast30Days = QrFile::withoutTrashed()->where('last_scanned_at', '>=', now()->subDays(30))
                ->sum('scan_count') ?? 0;

            // Optimizar query con select específico
            $activityByFolder = QrFile::withoutTrashed()->select('folder_name', DB::raw('count(*) as document_count'), DB::raw('sum(scan_count) as total_scans'))
                ->groupBy('folder_name')
                ->orderBy('total_scans', 'desc')
                ->limit(10)
                ->get()
                ->map(function ($item) {
                    return [
                        'folder_name' => $item->folder_name,
                        'document_count' => $item->document_count,
                        'total_scans' => $item->total_scans ?? 0,
                    ];
                });

            $recentDocuments = QrFile::withoutTrashed()->orderBy('created_at', 'desc')
                ->limit(5)
                ->get()
                ->map(function ($doc) {
                    return [
                        'id' => $doc->id,
                        'original_filename' => $doc->original_filename,
                        'folder_name' => $doc->folder_name,
                        'scan_count' => $doc->scan_count,
                        'last_scanned_at' => $doc->last_scanned_at?->format('Y-m-d H:i:s'),
                        'status' => $doc->status,
                    ];
                });

            $stats = [
                'total_documents' => $totalDocuments,
                'total_scans' => $totalScans,
                'scans_last_30_days' => $scansLast30Days,
                'completed_documents' => $completedDocuments,
                'pending_documents' => $pendingDocuments,
                'last_upload' => QrFile::withoutTrashed()->latest('created_at')->first()?->created_at?->format('Y-m-d H:i:s'),
                'activity_by_folder' => $activityByFolder,
                'recent_documents' => $recentDocuments,
            ];

            return response()->json([
                'success' => true,
                'data' => $stats
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener estadísticas: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Regenerar QR code con URL actualizada (para corregir URLs con localhost)
     * 
     * @param string $qrId
     * @param Request $request
     * @return JsonResponse
     */
    public function regenerateQr(string $qrId, Request $request): JsonResponse
    {
        try {
            if (!\App\Helpers\QrIdValidator::isValid($qrId)) {
                return response()->json([
                    'success' => false,
                    'message' => 'ID de documento inválido'
                ], 400);
            }
            
            $document = QrFile::where('qr_id', $qrId)->firstOrFail();

            $newQrUrl = \App\Helpers\UrlHelper::url("/api/view/{$qrId}", $request);

            $newQrPath = $this->qrGenerator->generate($newQrUrl, $qrId);

            $document->update([
                'qr_path' => $newQrPath
            ]);
            
            CacheHelper::invalidateDocumentsCache();

            if ($document->getOriginal('qr_path') && $document->getOriginal('qr_path') !== $newQrPath) {
                try {
                    $oldQrFilename = basename($document->getOriginal('qr_path'));
                    if (Storage::disk('qrcodes')->exists($oldQrFilename)) {
                        Storage::disk('qrcodes')->delete($oldQrFilename);
                    }
                } catch (\Exception $e) {
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'QR code regenerado exitosamente',
                'data' => [
                    'qr_id' => $qrId,
                    'qr_url' => $newQrUrl,
                    'qr_image_url' => \App\Helpers\UrlHelper::url("/api/files/qr/{$qrId}", $request)
                ]
            ], 200);

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Documento no encontrado'
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error al regenerar QR: ' . $e->getMessage(), [
                'qr_id' => $qrId,
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al regenerar QR code: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Verificar si un código (folder_name) ya existe
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function checkCodeExists(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'folder_name' => 'required|string|max:100',
            ]);

            $folderName = $request->input('folder_name');
            $exists = QrFile::withoutTrashed()->where('folder_name', $folderName)->exists();

            return response()->json([
                'success' => true,
                'exists' => $exists,
                'message' => $exists 
                    ? 'Este código ya existe en el sistema' 
                    : 'Código disponible'
            ], 200);

        } catch (\Exception $e) {
            Log::error('Error al verificar código: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al verificar el código'
            ], 500);
        }
    }

    /**
     * Crear documento y generar QR sin PDF (para flujo "Adjuntar")
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function create(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'folder_name' => 'required|string|max:100|unique:qr_files,folder_name',
            ]);

            $folderName = $request->input('folder_name');

            do {
                $qrId = \Illuminate\Support\Str::random(12);
            } while (QrFile::withoutTrashed()->where('qr_id', $qrId)->exists());

            $qrUrl = \App\Helpers\UrlHelper::url("/api/view/{$qrId}", $request);
            $qrPath = $this->qrGenerator->generate($qrUrl, $qrId);

            $document = QrFile::create([
                'qr_id' => $qrId,
                'folder_name' => $folderName,
                'qr_path' => $qrPath,
                'status' => 'uploaded',
                'file_path' => null,
                'original_filename' => null,
                'file_size' => null,
            ]);

            CacheHelper::invalidateDocumentsCache();

            return response()->json([
                'success' => true,
                'message' => 'Documento creado y QR generado exitosamente',
                'data' => [
                    'qr_id' => $qrId,
                    'qr_url' => $qrUrl,
                    'qr_image_url' => \App\Helpers\UrlHelper::url("/api/files/qr/{$qrId}", $request),
                    'folder_name' => $folderName,
                ]
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error de validación',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error al crear documento sin PDF: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al crear documento: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Adjuntar PDF a un documento existente (sin procesar)
     * 
     * @param string $qrId
     * @param Request $request
     * @return JsonResponse
     */
    public function attachPdf(string $qrId, Request $request): JsonResponse
    {
        try {
            // Aumentar límites para procesar PDFs grandes (hasta 500MB)
            ini_set('memory_limit', '1024M'); // 1GB para PDFs muy grandes
            set_time_limit(600); // 10 minutos para PDFs grandes
            
            if (!\App\Helpers\QrIdValidator::isValid($qrId)) {
                return response()->json([
                    'success' => false,
                    'message' => 'ID de documento inválido'
                ], 400);
            }
            
            // Log detallado del request para debugging
            Log::info('Request recibido en attachPdf:', [
                'qr_id' => $qrId,
                'method' => $request->method(),
                'content_type' => $request->header('Content-Type'),
                'content_length' => $request->header('Content-Length'),
                'has_file' => $request->hasFile('file'),
                'all_inputs' => array_keys($request->all()),
                'all_files' => array_keys($request->allFiles()),
                'php_upload_max' => ini_get('upload_max_filesize'),
                'php_post_max' => ini_get('post_max_size'),
                'php_memory_limit' => ini_get('memory_limit'),
            ]);
            
            // Validar que el archivo existe
            if (!$request->hasFile('file')) {
                // Verificar si el problema es el tamaño del archivo
                $contentLength = $request->header('Content-Length');
                $postMaxSize = ini_get('post_max_size');
                $uploadMaxSize = ini_get('upload_max_filesize');
                
                // Convertir límites a bytes para comparar
                $postMaxBytes = $this->convertToBytes($postMaxSize);
                $uploadMaxBytes = $this->convertToBytes($uploadMaxSize);
                
                $errorDetails = [
                    'qr_id' => $qrId,
                    'has_file' => $request->hasFile('file'),
                    'all_inputs' => array_keys($request->all()),
                    'all_files' => array_keys($request->allFiles()),
                    'content_length' => $contentLength,
                    'php_upload_max' => $uploadMaxSize,
                    'php_post_max' => $postMaxSize,
                    'php_memory_limit' => ini_get('memory_limit'),
                ];
                
                // Si hay Content-Length, verificar si excede los límites
                if ($contentLength) {
                    $contentLengthInt = (int) $contentLength;
                    if ($contentLengthInt > $postMaxBytes) {
                        $errorDetails['error'] = 'El archivo excede post_max_size';
                        $errorDetails['file_size_mb'] = round($contentLengthInt / 1024 / 1024, 2);
                        $errorDetails['post_max_mb'] = round($postMaxBytes / 1024 / 1024, 2);
                    } elseif ($contentLengthInt > $uploadMaxBytes) {
                        $errorDetails['error'] = 'El archivo excede upload_max_filesize';
                        $errorDetails['file_size_mb'] = round($contentLengthInt / 1024 / 1024, 2);
                        $errorDetails['upload_max_mb'] = round($uploadMaxBytes / 1024 / 1024, 2);
                    }
                }
                
                Log::error('No se recibió archivo en attachPdf:', $errorDetails);
                
                $errorMessage = 'No se recibió ningún archivo. Por favor, selecciona un archivo PDF e intenta nuevamente.';
                if (isset($errorDetails['error'])) {
                    $errorMessage .= ' ' . $errorDetails['error'] . '. Verifica la configuración de PHP.';
                }
                
                return response()->json([
                    'success' => false,
                    'message' => $errorMessage,
                    'errors' => ['file' => ['El archivo es requerido']],
                    'debug' => $errorDetails
                ], 422);
            }
            
            $file = $request->file('file');
            $fileSize = $file->getSize();
            $mimeType = $file->getMimeType();
            $originalName = $file->getClientOriginalName();
            
            // Log para debugging
            Log::info('Archivo recibido en attachPdf:', [
                'qr_id' => $qrId,
                'file_name' => $originalName,
                'file_size' => $fileSize,
                'file_size_mb' => round($fileSize / 1024 / 1024, 2),
                'mime_type' => $mimeType,
                'is_valid' => $file->isValid()
            ]);
            
            // Validar tamaño antes de la validación de Laravel
            $maxSize = 512000; // 500MB en KB
            if ($fileSize > $maxSize * 1024) {
                return response()->json([
                    'success' => false,
                    'message' => "El archivo PDF es demasiado grande. Tamaño máximo: 500MB. Tamaño actual: " . round($fileSize / 1024 / 1024, 2) . "MB",
                    'errors' => ['file' => ['El archivo excede el tamaño máximo permitido']]
                ], 422);
            }
            
            // Validar tipo MIME (más permisivo)
            $allowedMimes = ['application/pdf', 'application/x-pdf', 'application/octet-stream'];
            $hasPdfExtension = str_ends_with(strtolower($originalName), '.pdf');
            
            // Si el MIME type no es PDF pero la extensión sí, verificar el contenido
            if (!in_array($mimeType, $allowedMimes)) {
                if ($hasPdfExtension) {
                    // Verificar header del archivo para confirmar que es PDF
                    $handle = fopen($file->getRealPath(), 'rb');
                    $header = fread($handle, 4);
                    fclose($handle);
                    
                    if ($header !== '%PDF') {
                        return response()->json([
                            'success' => false,
                            'message' => "El archivo no es un PDF válido. Tipo MIME: {$mimeType}",
                            'errors' => ['file' => ['El archivo debe ser un PDF válido']]
                        ], 422);
                    }
                    // Si el header es correcto, continuar aunque el MIME type no coincida
                    Log::info('PDF con extensión .pdf pero MIME type diferente, header verificado:', [
                        'mime_type' => $mimeType,
                        'file_name' => $originalName
                    ]);
                } else {
                    return response()->json([
                        'success' => false,
                        'message' => "El archivo debe ser un PDF. Tipo recibido: {$mimeType}",
                        'errors' => ['file' => ['El archivo debe ser un PDF']]
                    ], 422);
                }
            }
            
            // Validación de Laravel (más permisiva - usar Validator directamente)
            try {
                $validator = Validator::make($request->all(), [
                    'file' => [
                        'required',
                        'file',
                        function ($attribute, $value, $fail) use ($mimeType, $originalName, $hasPdfExtension, $file) {
                            // Validar extensión
                            if (!$hasPdfExtension) {
                                $fail('El archivo debe tener extensión .pdf');
                                return;
                            }
                            
                            // Validar MIME type o header
                            $allowedMimes = ['application/pdf', 'application/x-pdf', 'application/octet-stream'];
                            if (!in_array($mimeType, $allowedMimes)) {
                                // Verificar header como último recurso
                                $handle = fopen($file->getRealPath(), 'rb');
                                $header = fread($handle, 4);
                                fclose($handle);
                                
                                if ($header !== '%PDF') {
                                    $fail("El archivo no es un PDF válido. Tipo MIME: {$mimeType}");
                                }
                            }
                        },
                        'max:512000' // Máximo 500MB
                    ],
                ], [
                    'file.required' => 'El archivo PDF es requerido',
                    'file.file' => 'El archivo debe ser un archivo válido',
                    'file.max' => 'El archivo PDF no puede exceder 500MB. Tamaño actual: ' . round($fileSize / 1024 / 1024, 2) . 'MB'
                ]);
                
                if ($validator->fails()) {
                    throw new \Illuminate\Validation\ValidationException($validator);
                }
            } catch (\Illuminate\Validation\ValidationException $e) {
                Log::error('Error de validación Laravel en attachPdf:', [
                    'qr_id' => $qrId,
                    'errors' => $e->errors(),
                    'file_name' => $originalName,
                    'file_size' => $fileSize,
                    'file_size_mb' => round($fileSize / 1024 / 1024, 2),
                    'mime_type' => $mimeType,
                    'php_upload_max' => ini_get('upload_max_filesize'),
                    'php_post_max' => ini_get('post_max_size'),
                    'file_is_valid' => $file->isValid(),
                    'file_error_code' => $file->getError()
                ]);
                
                $errorMessage = 'Error de validación';
                if ($e->errors()) {
                    $firstError = reset($e->errors());
                    if (is_array($firstError) && count($firstError) > 0) {
                        $errorMessage = $firstError[0];
                    } elseif (is_string($firstError)) {
                        $errorMessage = $firstError;
                    }
                }
                
                return response()->json([
                    'success' => false,
                    'message' => $errorMessage,
                    'errors' => $e->errors(),
                    'debug' => [
                        'file_size_mb' => round($fileSize / 1024 / 1024, 2),
                        'mime_type' => $mimeType,
                        'php_upload_max' => ini_get('upload_max_filesize'),
                        'php_post_max' => ini_get('post_max_size'),
                        'file_is_valid' => $file->isValid(),
                        'file_error_code' => $file->getError()
                    ]
                ], 422);
            }

            $document = QrFile::where('qr_id', $qrId)->firstOrFail();

            // El archivo ya fue obtenido arriba
            $originalFilename = $file->getClientOriginalName();
            // $fileSize ya fue obtenido arriba
            
            $integrityCheck = $this->pdfValidator->validatePdfIntegrity($file);
            if (!$integrityCheck['valid']) {
                Log::error('Error de integridad al adjuntar PDF:', [
                    'message' => $integrityCheck['message'],
                    'qr_id' => $qrId,
                    'file_name' => $originalFilename,
                    'file_size' => $fileSize,
                    'error' => $integrityCheck['error'] ?? 'N/A'
                ]);
                
                return response()->json([
                    'success' => false,
                    'message' => $integrityCheck['message']
                ], 422);
            }
            
            // Log de advertencia si se saltó validación FPDI (para debugging)
            if (isset($integrityCheck['skip_fpdi_validation']) && $integrityCheck['skip_fpdi_validation']) {
                Log::info('PDF validado con validación básica (FPDI saltado):', [
                    'qr_id' => $qrId,
                    'file_name' => $originalFilename,
                    'file_size' => $fileSize,
                    'warning' => $integrityCheck['warning'] ?? 'N/A'
                ]);
            }

            // NOTA: No validamos el número de páginas en attachPdf porque:
            // 1. El QR se coloca manualmente en el editor
            // 2. El PDF puede tener múltiples páginas
            // 3. La validación de 1 página solo aplica al módulo de drag and drop (upload normal)

            $oldFilePath = $document->file_path;
            $storageFolder = null;
            
            // Optimizar estructura de almacenamiento: organizar por tipo y fecha
            // Estructura: uploads/TIPO/YYYY-MM/qr_id/nombre_archivo.pdf
            // Esto mejora el rendimiento y facilita el mantenimiento
            if ($document->file_path) {
                $storageFolder = dirname($document->file_path);
                if (!Storage::disk('local')->exists($storageFolder)) {
                    Storage::disk('local')->makeDirectory($storageFolder, true); // recursive
                }
            } else {
                $documentType = \App\Models\QrFile::extractDocumentType($document->folder_name);
                $yearMonth = $document->created_at->format('Y-m'); // YYYY-MM para mejor organización
                
                // Estructura optimizada: uploads/TIPO/YYYY-MM/qr_id/
                $storageFolder = "uploads/{$documentType}/{$yearMonth}/{$qrId}";
                Storage::disk('local')->makeDirectory($storageFolder, true); // recursive
            }
            
            // Sanitizar nombre de archivo para evitar problemas de almacenamiento
            $safeFilename = $this->sanitizeFilename($originalFilename);
            
            // Almacenar archivo con nombre seguro
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
            
            if ($oldFilePath && Storage::disk('local')->exists($oldFilePath) && $oldFilePath !== $storedPath) {
                Storage::disk('local')->delete($oldFilePath);
            }

            $document->update([
                'file_path' => $storedPath,
                'original_filename' => $originalFilename,
                'file_size' => $fileSize,
                'status' => 'uploaded',
            ]);

            CacheHelper::invalidateDocumentsCache();

            return response()->json([
                'success' => true,
                'message' => 'PDF adjuntado exitosamente',
                'data' => [
                    'pdf_url' => \App\Helpers\UrlHelper::url("/api/files/pdf-original/{$qrId}", $request),
                    'original_filename' => $originalFilename,
                    'file_size' => $fileSize,
                ]
            ], 200);

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Documento no encontrado'
            ], 404);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error de validación',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error al adjuntar PDF: ' . $e->getMessage(), [
                'qr_id' => $qrId,
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al adjuntar PDF: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Convertir tamaño de PHP ini (ej: "500M", "1G") a bytes
     */
    private function convertToBytes(string $value): int
    {
        $value = trim($value);
        $last = strtolower($value[strlen($value) - 1]);
        $value = (int) $value;
        
        switch ($last) {
            case 'g':
                $value *= 1024;
            case 'm':
                $value *= 1024;
            case 'k':
                $value *= 1024;
        }
        
        return $value;
    }
    
    /**
     * Sanitizar nombre de archivo para almacenamiento seguro
     * Elimina caracteres peligrosos y asegura compatibilidad con sistemas de archivos
     */
    private function sanitizeFilename(string $filename): string
    {
        // Obtener extensión
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $name = pathinfo($filename, PATHINFO_FILENAME);
        
        // Remover caracteres peligrosos y espacios múltiples
        $name = preg_replace('/[^a-zA-Z0-9_\-\.\s]/', '', $name);
        $name = preg_replace('/\s+/', '_', trim($name));
        
        // Limitar longitud (máximo 200 caracteres para nombre + extensión)
        $maxLength = 200 - strlen($extension) - 1; // -1 para el punto
        if (strlen($name) > $maxLength) {
            $name = substr($name, 0, $maxLength);
        }
        
        // Si el nombre está vacío, usar timestamp
        if (empty($name)) {
            $name = 'document_' . time();
        }
        
        return $name . '.' . $extension;
    }
}

