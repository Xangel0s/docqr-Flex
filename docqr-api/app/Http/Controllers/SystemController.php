<?php

namespace App\Http\Controllers;

use App\Models\QrFile;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

/**
 * Controlador para información del sistema y optimizaciones
 */
class SystemController extends Controller
{
    /**
     * Obtener estado de compresión y optimización del sistema
     * 
     * @return JsonResponse
     */
    public function compressionStatus(): JsonResponse
    {
        try {
            // Documentos completados hace más de 6 meses sin comprimir
            $cutoffDate = Carbon::now()->subMonths(6)->startOfMonth();
            
            $pendingCompression = QrFile::where('status', 'completed')
                ->where('created_at', '<', $cutoffDate)
                ->where('archived', false)
                ->count();

            // Tamaño total de archivos sin comprimir (estimado)
            $totalSize = QrFile::where('status', 'completed')
                ->where('created_at', '<', $cutoffDate)
                ->where('archived', false)
                ->sum('file_size');

            // Espacio que se ahorraría (estimado 50% de compresión)
            $estimatedSavings = $totalSize * 0.5;

            // Documentos por tipo que necesitan compresión
            $byType = QrFile::where('status', 'completed')
                ->where('created_at', '<', $cutoffDate)
                ->where('archived', false)
                ->select('folder_name', DB::raw('count(*) as count'))
                ->groupBy('folder_name')
                ->get()
                ->map(function ($item) {
                    $type = $this->extractDocumentType($item->folder_name);
                    return [
                        'type' => $type,
                        'count' => $item->count
                    ];
                })
                ->groupBy('type')
                ->map(function ($group) {
                    return $group->sum('count');
                });

            return response()->json([
                'success' => true,
                'data' => [
                    'needs_compression' => $pendingCompression > 0,
                    'pending_count' => $pendingCompression,
                    'total_size_bytes' => $totalSize,
                    'total_size_mb' => round($totalSize / (1024 * 1024), 2),
                    'estimated_savings_mb' => round($estimatedSavings / (1024 * 1024), 2),
                    'by_type' => $byType,
                    'cutoff_date' => $cutoffDate->format('Y-m-d'),
                    'message' => $pendingCompression > 0 
                        ? "Hay {$pendingCompression} documentos antiguos pendientes de compresión"
                        : "El sistema está optimizado"
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener estado: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Extraer tipo de documento del folder_name
     */
    private function extractDocumentType(string $folderName): string
    {
        $parts = explode('-', $folderName);
        $type = strtoupper(trim($parts[0] ?? ''));
        
        $allowedTypes = ['CE', 'IN', 'SU'];
        return in_array($type, $allowedTypes) ? $type : 'OTROS';
    }
}

