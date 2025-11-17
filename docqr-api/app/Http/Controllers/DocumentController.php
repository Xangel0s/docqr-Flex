<?php

namespace App\Http\Controllers;

use App\Models\QrFile;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Controlador para gestión de documentos
 */
class DocumentController extends Controller
{
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
                    // URLs generadas dinámicamente
                    'qr_url' => url("/api/view/{$document->qr_id}"),
                    'pdf_url' => url("/api/files/pdf/{$document->qr_id}"), // PDF final (si existe) o original
                    'pdf_original_url' => url("/api/files/pdf-original/{$document->qr_id}"), // Siempre PDF original (para editor)
                    'qr_image_url' => url("/api/files/qr/{$document->qr_id}"),
                    'final_pdf_url' => $document->final_path 
                        ? url("/api/files/pdf/{$document->qr_id}")
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
                    'qr_url' => $document->view_url,
                    'pdf_url' => url("/api/files/pdf/{$document->qr_id}"), // PDF final (si existe) o original
                    'pdf_original_url' => url("/api/files/pdf-original/{$document->qr_id}"), // Siempre PDF original (para editor)
                    'qr_image_url' => url("/api/files/qr/{$document->qr_id}"),
                    'final_pdf_url' => $document->final_path 
                        ? url("/api/files/pdf/{$document->qr_id}")
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
                    'qr_url' => $document->view_url,
                    'pdf_url' => url("/api/files/pdf/{$document->qr_id}"), // PDF final (si existe) o original
                    'pdf_original_url' => url("/api/files/pdf-original/{$document->qr_id}"), // Siempre PDF original (para editor)
                    'qr_image_url' => url("/api/files/qr/{$document->qr_id}"),
                    'final_pdf_url' => $document->final_path 
                        ? url("/api/files/pdf/{$document->qr_id}")
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
            $document = QrFile::withTrashed()->findOrFail($id);

            Log::info('Eliminando documento:', [
                'id' => $document->id,
                'qr_id' => $document->qr_id,
                'folder_name' => $document->folder_name
            ]);

            // Eliminar archivos físicos (ajustado para nueva estructura)
            if ($document->file_path && \Storage::disk('local')->exists($document->file_path)) {
                \Storage::disk('local')->delete($document->file_path);
                Log::info('Archivo PDF eliminado: ' . $document->file_path);
            }
            
            if ($document->qr_path) {
                $qrFilename = basename($document->qr_path);
                if (\Storage::disk('qrcodes')->exists($qrFilename)) {
                    \Storage::disk('qrcodes')->delete($qrFilename);
                    Log::info('QR eliminado: ' . $qrFilename);
                }
            }
            
            if ($document->final_path) {
                // La ruta ahora es: final/CE/filename.pdf
                $finalPath = str_replace('final/', '', $document->final_path);
                if (\Storage::disk('final')->exists($finalPath)) {
                    \Storage::disk('final')->delete($finalPath);
                    Log::info('PDF final eliminado: ' . $finalPath);
                }
            }

            // Eliminar registro PERMANENTEMENTE de la base de datos
            // Usar forceDelete() para eliminar realmente, no solo marcar como eliminado
            $document->forceDelete();
            
            Log::info('Documento eliminado permanentemente de la BD:', [
                'id' => $id,
                'qr_id' => $document->qr_id
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Documento eliminado exitosamente de la base de datos'
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
            // Estadísticas básicas
            $totalDocuments = QrFile::count();
            $totalScans = QrFile::sum('scan_count') ?? 0;
            $completedDocuments = QrFile::where('status', 'completed')->count();
            $pendingDocuments = QrFile::where('status', 'uploaded')->count();
            
            // Escaneos de los últimos 30 días
            $scansLast30Days = QrFile::where('last_scanned_at', '>=', now()->subDays(30))
                ->sum('scan_count') ?? 0;

            // Actividad por carpeta (agrupado por folder_name)
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

            // Documentos recientes (últimos 5)
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

            $stats = [
                'total_documents' => $totalDocuments,
                'total_scans' => $totalScans,
                'scans_last_30_days' => $scansLast30Days,
                'completed_documents' => $completedDocuments,
                'pending_documents' => $pendingDocuments,
                'last_upload' => QrFile::latest('created_at')->first()?->created_at?->format('Y-m-d H:i:s'),
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
}

