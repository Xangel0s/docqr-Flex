<?php

namespace App\Http\Controllers;

use App\Models\QrFile;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use ZipArchive;

/**
 * Controlador para gestión manual de compresión
 */
class CompressionController extends Controller
{
    /**
     * Listar documentos agrupados por mes y tipo para compresión
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function listCompressible(Request $request): JsonResponse
    {
        try {
            $monthsBack = (int) $request->input('months', 6);
            $cutoffDate = Carbon::now()->subMonths($monthsBack)->startOfMonth();

            // Obtener documentos completados agrupados por mes y tipo
            $documents = QrFile::where('status', 'completed')
                ->where('created_at', '<', $cutoffDate)
                ->where('archived', false)
                ->whereNotNull('final_path')
                ->get();

            // Agrupar por mes y tipo
            $grouped = [];
            foreach ($documents as $doc) {
                // Extraer mes del nombre del archivo o de created_at
                $filename = basename($doc->final_path);
                $monthYear = Carbon::parse($doc->created_at)->format('Ym');
                
                if (preg_match('/-(\d{6})-\w+-/', $filename, $matches)) {
                    $monthYear = $matches[1];
                }

                // Extraer tipo de la nueva estructura: final/{TIPO}/{YYYYMM}/{qr_id}/documento.pdf
                // O estructura antigua: final/{TIPO}/documento.pdf
                $pathParts = explode('/', $doc->final_path);
                $type = 'OTROS';
                if (count($pathParts) >= 2) {
                    $type = strtoupper($pathParts[1] ?? 'OTROS');
                    // Si pathParts[2] es un año/mes (6 dígitos), es nueva estructura
                    // En nueva estructura, el mes está en pathParts[2]
                    if (count($pathParts) >= 3 && preg_match('/^(\d{6})$/', $pathParts[2] ?? '', $matches)) {
                        $monthYear = $matches[1]; // Ya extraído arriba, pero confirmar
                    }
                }

                $key = "{$type}|{$monthYear}";

                if (!isset($grouped[$key])) {
                    $grouped[$key] = [
                        'type' => $type,
                        'month' => $monthYear,
                        'month_formatted' => $this->formatMonth($monthYear),
                        'count' => 0,
                        'total_size_mb' => 0,
                        'documents' => []
                    ];
                }

                $grouped[$key]['count']++;
                $grouped[$key]['total_size_mb'] += ($doc->file_size / (1024 * 1024));
                $grouped[$key]['documents'][] = [
                    'id' => $doc->id,
                    'qr_id' => $doc->qr_id,
                    'folder_name' => $doc->folder_name,
                    'original_filename' => $doc->original_filename,
                    'file_size_mb' => round($doc->file_size / (1024 * 1024), 2),
                    'created_at' => $doc->created_at->format('Y-m-d')
                ];
            }

            // Convertir a array indexado
            $result = array_values($grouped);

            return response()->json([
                'success' => true,
                'data' => $result,
                'total_groups' => count($result),
                'total_documents' => $documents->count()
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al listar documentos: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Comprimir documentos por tipo y mes específicos
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function compressByMonth(Request $request): JsonResponse
    {
        try {
            $validator = \Validator::make($request->all(), [
                'type' => 'required|string|in:CE,IN,SU,OTROS',
                'month' => 'required|string|regex:/^\d{6}$/', // Formato YYYYMM
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Datos inválidos',
                    'errors' => $validator->errors()
                ], 422);
            }

            $type = $request->input('type');
            $monthYear = $request->input('month');

            // Buscar documentos del tipo y mes especificados
            $documents = QrFile::where('status', 'completed')
                ->where('archived', false)
                ->whereNotNull('final_path')
                ->get()
                ->filter(function ($doc) use ($type, $monthYear) {
                    // Verificar tipo
                    $pathParts = explode('/', $doc->final_path);
                    $docType = count($pathParts) >= 2 ? strtoupper($pathParts[1]) : 'OTROS';
                    
                    // Verificar mes
                    $filename = basename($doc->final_path);
                    $docMonth = Carbon::parse($doc->created_at)->format('Ym');
                    if (preg_match('/-(\d{6})-\w+-/', $filename, $matches)) {
                        $docMonth = $matches[1];
                    }

                    return $docType === $type && $docMonth === $monthYear;
                });

            if ($documents->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'No hay documentos para comprimir con esos criterios'
                ], 404);
            }

            // Comprimir
            $result = $this->compressDocuments($type, $monthYear, $documents->toArray());

            if ($result['success']) {
                // Marcar como archivados
                foreach ($documents as $doc) {
                    $doc->update([
                        'archived' => true,
                        'archive_path' => $result['archive_path']
                    ]);
                }

                return response()->json([
                    'success' => true,
                    'message' => "Comprimidos {$result['count']} documentos exitosamente",
                    'data' => [
                        'archive_path' => $result['archive_path'],
                        'zip_size_mb' => round($result['zip_size'] / (1024 * 1024), 2),
                        'documents_count' => $result['count']
                    ]
                ], 200);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Error al comprimir: ' . $result['error']
                ], 500);
            }

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Exportar/Descargar ZIP comprimido
     * 
     * @param Request $request
     * @return \Symfony\Component\HttpFoundation\BinaryFileResponse|\Illuminate\Http\JsonResponse
     */
    public function downloadZip(Request $request)
    {
        try {
            $type = $request->input('type');
            $monthYear = $request->input('month');

            if (!$type || !$monthYear) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tipo y mes son requeridos'
                ], 422);
            }

            $zipPath = Storage::disk('local')->path("archived/{$type}/{$type}-{$monthYear}.zip");

            if (!file_exists($zipPath)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Archivo ZIP no encontrado'
                ], 404);
            }

            return response()->download($zipPath, "{$type}-{$monthYear}.zip");

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al descargar: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Comprimir documentos
     */
    private function compressDocuments(string $type, string $monthYear, array $documents): array
    {
        try {
            $archiveFolder = "archived/{$type}";
            Storage::disk('local')->makeDirectory($archiveFolder);

            $zipFilename = "{$type}-{$monthYear}.zip";
            $zipPath = Storage::disk('local')->path("{$archiveFolder}/{$zipFilename}");

            $zip = new ZipArchive();
            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                return ['success' => false, 'error' => 'No se pudo crear el archivo ZIP'];
            }

            $added = 0;
            foreach ($documents as $docData) {
                $doc = QrFile::find($docData['id']);
                if (!$doc) continue;

                // Agregar PDF final
                if ($doc->final_path) {
                    $finalPath = str_replace('final/', '', $doc->final_path);
                    $fullPath = Storage::disk('final')->path($finalPath);
                    
                    if (file_exists($fullPath)) {
                        $zipName = "{$doc->qr_id}-{$doc->original_filename}";
                        $zip->addFile($fullPath, $zipName);
                        $added++;
                    }
                }

                // Agregar QR
                if ($doc->qr_path) {
                    $qrPath = Storage::disk('qrcodes')->path(basename($doc->qr_path));
                    if (file_exists($qrPath)) {
                        $zip->addFile($qrPath, "QR-{$doc->qr_id}.png");
                    }
                }
            }

            $zip->close();

            if ($added === 0) {
                @unlink($zipPath);
                return ['success' => false, 'error' => 'No se agregaron archivos al ZIP'];
            }

            // Eliminar PDFs finales originales
            foreach ($documents as $docData) {
                $doc = QrFile::find($docData['id']);
                if ($doc && $doc->final_path) {
                    $finalPath = str_replace('final/', '', $doc->final_path);
                    Storage::disk('final')->delete($finalPath);
                }
            }

            $zipSize = filesize($zipPath);

            return [
                'success' => true,
                'count' => $added,
                'archive_path' => "{$archiveFolder}/{$zipFilename}",
                'zip_size' => $zipSize
            ];

        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Formatear mes para mostrar
     */
    private function formatMonth(string $monthYear): string
    {
        if (strlen($monthYear) === 6) {
            $year = substr($monthYear, 0, 4);
            $month = substr($monthYear, 4, 2);
            $monthNames = [
                '01' => 'Enero', '02' => 'Febrero', '03' => 'Marzo',
                '04' => 'Abril', '05' => 'Mayo', '06' => 'Junio',
                '07' => 'Julio', '08' => 'Agosto', '09' => 'Septiembre',
                '10' => 'Octubre', '11' => 'Noviembre', '12' => 'Diciembre'
            ];
            return "{$monthNames[$month]} {$year}";
        }
        return $monthYear;
    }
}

