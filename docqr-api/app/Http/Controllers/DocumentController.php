<?php

namespace App\Http\Controllers;

use App\Models\QrFile;
use App\Services\QrGeneratorService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Controlador para gestión de documentos
 */
class DocumentController extends Controller
{
    protected $qrGenerator;

    public function __construct(QrGeneratorService $qrGenerator)
    {
        $this->qrGenerator = $qrGenerator;
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
            $query = QrFile::query();

            // Filtro por carpeta específica
            if ($request->has('folder') && $request->folder) {
                $query->where('folder_name', $request->folder);
            }

            // Filtro por tipo de documento (CE, IN, SU)
            if ($request->has('type') && $request->type && $request->type !== 'all') {
                $query->where('folder_name', 'like', $request->type . '-%');
            }

            // Filtro por estado
            if ($request->has('status') && $request->status && $request->status !== 'all') {
                $query->where('status', $request->status);
            }

            // Filtro por fecha (rango)
            if ($request->has('date_from') && $request->date_from) {
                $query->whereDate('created_at', '>=', $request->date_from);
            }
            if ($request->has('date_to') && $request->date_to) {
                $query->whereDate('created_at', '<=', $request->date_to);
            }

            // Filtro por escaneos
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

            // Búsqueda por nombre de archivo o carpeta
            if ($request->has('search') && $request->search) {
                $search = $request->search;
                $query->where(function($q) use ($search) {
                    $q->where('original_filename', 'like', '%' . $search . '%')
                      ->orWhere('folder_name', 'like', '%' . $search . '%');
                });
            }

            // Ordenamiento
            $sortBy = $request->input('sort_by', 'created_at');
            $sortOrder = $request->input('sort_order', 'desc');
            
            // Validar campos de ordenamiento
            $allowedSorts = ['created_at', 'original_filename', 'scan_count', 'folder_name'];
            if (!in_array($sortBy, $allowedSorts)) {
                $sortBy = 'created_at';
            }
            
            // Ordenar según el campo seleccionado
            $query->orderBy($sortBy, $sortOrder);

            // Paginación
            $perPage = $request->input('per_page', 15);
            $documents = $query->paginate($perPage);

            // Transformar documentos para incluir URLs
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
                    // URLs generadas dinámicamente (respetan protocolo de solicitud actual)
                    'qr_url' => \App\Helpers\UrlHelper::url("/api/view/{$document->qr_id}", request()),
                    'pdf_url' => \App\Helpers\UrlHelper::url("/api/files/pdf/{$document->qr_id}", request()), // PDF final (si existe) o original
                    'pdf_original_url' => \App\Helpers\UrlHelper::url("/api/files/pdf-original/{$document->qr_id}", request()), // Siempre PDF original (para editor)
                    'qr_image_url' => \App\Helpers\UrlHelper::url("/api/files/qr/{$document->qr_id}", request()),
                    'final_pdf_url' => $document->final_path 
                        ? \App\Helpers\UrlHelper::url("/api/files/pdf/{$document->qr_id}", request())
                        : null,
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $transformedDocuments->values()->all(),
                'meta' => [
                    'current_page' => $documents->currentPage(),
                    'last_page' => $documents->lastPage(),
                    'per_page' => $documents->perPage(),
                    'total' => $documents->total(),
                ]
            ], 200);

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
            // Validar qr_id contra inyección SQL
            if (!\App\Helpers\QrIdValidator::isValid($qrId)) {
                Log::warning('Intento de acceso con qr_id inválido en showByQrId:', ['qr_id' => $qrId]);
                return response()->json([
                    'success' => false,
                    'message' => 'ID de documento inválido'
                ], 400);
            }
            
            $document = QrFile::where('qr_id', $qrId)->firstOrFail();

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
            // Validar qr_id contra inyección SQL
            if (!\App\Helpers\QrIdValidator::isValid($qrId)) {
                Log::warning('Intento de acceso con qr_id inválido en updateFolderName:', ['qr_id' => $qrId]);
                return response()->json([
                    'success' => false,
                    'message' => 'ID de documento inválido'
                ], 400);
            }
            
            // Validar entrada
            // Permitir caracteres en español (incluyendo Ñ, ñ, acentos) y caracteres alfanuméricos
            $request->validate([
                'folder_name' => ['required', 'string', 'max:100', 'regex:/^(CE|IN|SU)-[A-Za-z0-9ÑñÁÉÍÓÚáéíóúÜü\-]+$/u']
            ], [
                'folder_name.required' => 'El nombre de carpeta es obligatorio',
                'folder_name.regex' => 'El formato debe ser: TIPO-CODIGO (ej: CE-12345, IN-ABC, SU-XYZ). Solo se permiten tipos: CE, IN, SU. Se permiten caracteres en español (Ñ, ñ, acentos).'
            ]);

            // Buscar documento
            $document = QrFile::where('qr_id', $qrId)->first();
            
            if (!$document) {
                Log::warning('Documento no encontrado al actualizar nombre de carpeta:', [
                    'qr_id' => $qrId,
                    'folder_name' => $request->folder_name
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'Documento no encontrado'
                ], 404);
            }
            
            $oldFolderName = $document->folder_name;
            $document->folder_name = $request->folder_name;
            
            // Guardar cambios
            $document->save();
            
            // Invalidar cache de estadísticas cuando se actualiza un documento
            Cache::forget('documents_stats_v2');

            Log::info('Nombre de carpeta actualizado exitosamente:', [
                'qr_id' => $qrId,
                'old_folder_name' => $oldFolderName,
                'new_folder_name' => $request->folder_name
            ]);

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
            Log::warning('Error de validación al actualizar nombre de carpeta:', [
                'qr_id' => $qrId,
                'errors' => $e->errors()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Error de validación',
                'errors' => $e->errors()
            ], 422);
        } catch (ModelNotFoundException $e) {
            Log::warning('Documento no encontrado (ModelNotFoundException):', [
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
            // Buscar el documento (incluyendo eliminados con soft delete)
            // Usar find() en lugar de findOrFail() para manejar documentos ya eliminados
            $document = QrFile::withTrashed()->find($id);

            // Si el documento no existe (ni siquiera con soft delete), ya fue eliminado
            if (!$document) {
                Log::info('Intento de eliminar documento que ya no existe:', ['id' => $id]);
                return response()->json([
                    'success' => true,
                    'message' => 'El documento ya fue eliminado anteriormente'
                ], 200);
            }

            // Si el documento está eliminado con soft delete pero aún existe en BD
            if ($document->trashed()) {
                Log::info('Eliminando documento que ya estaba marcado como eliminado (soft delete):', [
                    'id' => $document->id,
                    'qr_id' => $document->qr_id
                ]);
                // Continuar con la eliminación permanente
            } else {
                Log::info('Eliminando documento:', [
                    'id' => $document->id,
                    'qr_id' => $document->qr_id,
                    'folder_name' => $document->folder_name
                ]);
            }

            // Eliminar archivos físicos (ajustado para nueva estructura)
            // Verificar existencia antes de eliminar para evitar errores
            if ($document->file_path) {
                try {
                    if (\Storage::disk('local')->exists($document->file_path)) {
                        \Storage::disk('local')->delete($document->file_path);
                        Log::info('Archivo PDF eliminado: ' . $document->file_path);
                    }
                } catch (\Exception $e) {
                    Log::warning('Error al eliminar archivo PDF (continuando): ' . $e->getMessage(), [
                        'file_path' => $document->file_path
                    ]);
                }
            }
            
            if ($document->qr_path) {
                try {
                    $qrFilename = basename($document->qr_path);
                    if (\Storage::disk('qrcodes')->exists($qrFilename)) {
                        \Storage::disk('qrcodes')->delete($qrFilename);
                        Log::info('QR eliminado: ' . $qrFilename);
                    }
                } catch (\Exception $e) {
                    Log::warning('Error al eliminar QR (continuando): ' . $e->getMessage(), [
                        'qr_path' => $document->qr_path
                    ]);
                }
            }
            
            if ($document->final_path) {
                try {
                    // La ruta ahora es: final/{TIPO}/{YYYYMM}/{qr_id}/documento.pdf
                    $finalPath = str_replace('final/', '', $document->final_path);
                    if (\Storage::disk('final')->exists($finalPath)) {
                        \Storage::disk('final')->delete($finalPath);
                        Log::info('PDF final eliminado: ' . $finalPath);
                    }
                } catch (\Exception $e) {
                    Log::warning('Error al eliminar PDF final (continuando): ' . $e->getMessage(), [
                        'final_path' => $document->final_path
                    ]);
                }
            }

            // Guardar qr_id antes de eliminar para logging
            $qrId = $document->qr_id;
            
            // Eliminar registro PERMANENTEMENTE de la base de datos
            // Usar forceDelete() para eliminar realmente, no solo marcar como eliminado
            try {
                $document->forceDelete();
                
                Log::info('Documento eliminado permanentemente de la BD:', [
                    'id' => $id,
                    'qr_id' => $qrId
                ]);
            } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
                // Si ya fue eliminado entre la búsqueda y el forceDelete, no es un error crítico
                Log::info('Documento ya fue eliminado durante el proceso:', [
                    'id' => $id,
                    'qr_id' => $qrId
                ]);
            }

            // Invalidar cache de estadísticas
            Cache::forget('documents_stats_v2');

            return response()->json([
                'success' => true,
                'message' => 'Documento eliminado exitosamente'
            ], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            // Documento no encontrado (ya fue eliminado)
            Log::info('Intento de eliminar documento inexistente:', ['id' => $id]);
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
            // Cachear estadísticas por 2 minutos para mejorar rendimiento con múltiples usuarios
            // Se invalida automáticamente cuando se crean/actualizan documentos
            $cacheKey = 'documents_stats_v2';
            $stats = Cache::remember($cacheKey, 120, function () {
                // Estadísticas básicas (optimizadas con índices)
                $totalDocuments = QrFile::count();
                $totalScans = QrFile::sum('scan_count') ?? 0;
                $completedDocuments = QrFile::where('status', 'completed')->count();
                $pendingDocuments = QrFile::where('status', 'uploaded')->count();
                
                // Escaneos de los últimos 30 días (optimizado con índice en last_scanned_at)
                $scansLast30Days = QrFile::where('last_scanned_at', '>=', now()->subDays(30))
                    ->sum('scan_count') ?? 0;

                // Actividad por carpeta (optimizado con índice en folder_name)
                $activityByFolder = QrFile::select('folder_name', DB::raw('count(*) as document_count'), DB::raw('sum(scan_count) as total_scans'))
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

                // Documentos recientes (optimizado con índice en created_at)
                $recentDocuments = QrFile::orderBy('created_at', 'desc')
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

                return [
                    'total_documents' => $totalDocuments,
                    'total_scans' => $totalScans,
                    'scans_last_30_days' => $scansLast30Days,
                    'completed_documents' => $completedDocuments,
                    'pending_documents' => $pendingDocuments,
                    'last_upload' => QrFile::latest('created_at')->first()?->created_at?->format('Y-m-d H:i:s'),
                    'activity_by_folder' => $activityByFolder,
                    'recent_documents' => $recentDocuments,
                ];
            });

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
            // Validar qr_id contra inyección SQL
            if (!\App\Helpers\QrIdValidator::isValid($qrId)) {
                Log::warning('Intento de acceso con qr_id inválido en regenerateQr:', ['qr_id' => $qrId]);
                return response()->json([
                    'success' => false,
                    'message' => 'ID de documento inválido'
                ], 400);
            }
            
            $document = QrFile::where('qr_id', $qrId)->firstOrFail();

            // Generar nueva URL usando UrlHelper (detecta automáticamente protocolo y host)
            $newQrUrl = \App\Helpers\UrlHelper::url("/api/view/{$qrId}", $request);

            Log::info('Regenerando QR code:', [
                'qr_id' => $qrId,
                'old_qr_path' => $document->qr_path,
                'new_qr_url' => $newQrUrl
            ]);

            // Regenerar el QR code con la nueva URL
            $newQrPath = $this->qrGenerator->generate($newQrUrl, $qrId);

            // Actualizar el registro en la base de datos
            $document->update([
                'qr_path' => $newQrPath
            ]);
            
            // Invalidar cache de estadísticas cuando se regenera un QR
            Cache::forget('documents_stats_v2');

            // Eliminar el QR antiguo si existe y es diferente
            if ($document->getOriginal('qr_path') && $document->getOriginal('qr_path') !== $newQrPath) {
                try {
                    $oldQrFilename = basename($document->getOriginal('qr_path'));
                    if (Storage::disk('qrcodes')->exists($oldQrFilename)) {
                        Storage::disk('qrcodes')->delete($oldQrFilename);
                        Log::info('QR antiguo eliminado:', ['filename' => $oldQrFilename]);
                    }
                } catch (\Exception $e) {
                    Log::warning('No se pudo eliminar QR antiguo (no crítico):', [
                        'error' => $e->getMessage()
                    ]);
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
     * Crear documento y generar QR sin PDF (para flujo "Adjuntar")
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function create(Request $request): JsonResponse
    {
        try {
            // Validar entrada
            $request->validate([
                'folder_name' => 'required|string|max:100',
            ]);

            $folderName = $request->input('folder_name');

            // Generar ID único para el QR
            do {
                $qrId = \Illuminate\Support\Str::random(12);
            } while (QrFile::where('qr_id', $qrId)->exists());

            // Generar URL del QR
            $qrUrl = \App\Helpers\UrlHelper::url("/api/view/{$qrId}", $request);

            // Generar código QR
            $qrPath = $this->qrGenerator->generate($qrUrl, $qrId);

            // Crear registro en la base de datos (sin PDF)
            // Usar 'uploaded' como status inicial (valor permitido en ENUM)
            // Cuando se suba el PDF, se cambiará a 'completed' después de procesar
            $document = QrFile::create([
                'qr_id' => $qrId,
                'folder_name' => $folderName,
                'qr_path' => $qrPath,
                'status' => 'uploaded', // Estado inicial: documento creado (aunque sin PDF todavía)
                'file_path' => null, // Sin PDF todavía
                'original_filename' => null,
                'file_size' => null,
            ]);

            Log::info('Documento creado sin PDF (flujo Adjuntar):', [
                'qr_id' => $qrId,
                'folder_name' => $folderName
            ]);

            // Invalidar cache de estadísticas
            Cache::forget('documents_stats_v2');

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
            // Validar qr_id contra inyección SQL
            if (!\App\Helpers\QrIdValidator::isValid($qrId)) {
                Log::warning('Intento de acceso con qr_id inválido en attachPdf:', ['qr_id' => $qrId]);
                return response()->json([
                    'success' => false,
                    'message' => 'ID de documento inválido'
                ], 400);
            }
            
            // Validar request
            $request->validate([
                'file' => 'required|file|mimes:pdf|max:51200', // Máximo 50MB para PDFs complejos
            ]);

            // Buscar documento
            $document = QrFile::where('qr_id', $qrId)->firstOrFail();

            $file = $request->file('file');
            $originalFilename = $file->getClientOriginalName();
            $fileSize = $file->getSize();
            
            // Validar integridad del PDF (que no esté corrupto)
            $integrityCheck = $this->validatePdfIntegrity($file);
            if (!$integrityCheck['valid']) {
                Log::warning('PDF corrupto o inválido detectado en attachPdf:', [
                    'message' => $integrityCheck['message'],
                    'qr_id' => $qrId,
                    'file_name' => $originalFilename,
                    'error' => $integrityCheck['error'] ?? 'N/A'
                ]);
                
                return response()->json([
                    'success' => false,
                    'message' => $integrityCheck['message']
                ], 422);
            }

            // Determinar carpeta de almacenamiento
            // Si el documento ya tiene un file_path, usar la misma carpeta para reemplazar
            // Si no tiene file_path, crear nueva carpeta con estructura optimizada
            $storageFolder = null;
            
            if ($document->file_path) {
                // Extraer la carpeta del file_path existente
                // Ejemplo: uploads/CE/202511/{qr_id}/documento.pdf -> uploads/CE/202511/{qr_id}/
                $storageFolder = dirname($document->file_path);
                Log::info('Usando carpeta existente para reemplazar PDF:', [
                    'carpeta' => $storageFolder,
                    'file_path_anterior' => $document->file_path
                ]);
            } else {
                // NUEVA ESTRUCTURA OPTIMIZADA: uploads/{TIPO}/{YYYYMM}/{qr_id}/documento.pdf
                // Extraer tipo de documento del folder_name (CE, IN, SU)
                $documentType = \App\Models\QrFile::extractDocumentType($document->folder_name);
                
                // Obtener mes y año en formato YYYYMM (ej: 202511 para noviembre 2025)
                // Usar la fecha de creación del documento, no la fecha actual
                $monthYear = $document->created_at->format('Ym');
                
                // Crear estructura de carpetas: uploads/{TIPO}/{YYYYMM}/{qr_id}/
                $storageFolder = "uploads/{$documentType}/{$monthYear}/{$qrId}";
                Storage::disk('local')->makeDirectory($storageFolder);
                Log::info('Creando nueva carpeta para PDF:', ['carpeta' => $storageFolder]);
            }
            
            // Eliminar PDF anterior ANTES de guardar el nuevo (para asegurar reemplazo)
            if ($document->file_path && Storage::disk('local')->exists($document->file_path)) {
                Storage::disk('local')->delete($document->file_path);
                Log::info('PDF anterior eliminado:', ['path' => $document->file_path]);
            }
            
            // Guardar archivo con nombre original (sin prefijos) en la carpeta determinada
            $storedPath = Storage::disk('local')->putFileAs(
                $storageFolder,
                $file,
                $originalFilename
            );

            // Actualizar documento
            $document->update([
                'file_path' => $storedPath,
                'original_filename' => $originalFilename,
                'file_size' => $fileSize,
                'status' => 'uploaded', // Cambiar a 'uploaded' ya que tiene PDF
            ]);

            Log::info('PDF adjuntado sin procesar:', [
                'qr_id' => $qrId,
                'file_path' => $storedPath,
                'file_size' => $fileSize
            ]);

            // Invalidar cache de estadísticas
            Cache::forget('documents_stats_v2');

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
}

