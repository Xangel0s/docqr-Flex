<?php

namespace App\Console\Commands;

use App\Models\QrFile;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use ZipArchive;
use Carbon\Carbon;

/**
 * Comando para comprimir documentos antiguos por mes
 * 
 * Ejecutar: php artisan documents:compress
 * 
 * Este comando:
 * 1. Busca documentos completados de meses anteriores
 * 2. Los comprime en ZIP por mes y tipo
 * 3. Guarda los ZIPs en storage/app/archived/
 * 4. Elimina los PDFs originales después de comprimir
 */
class CompressOldDocuments extends Command
{
    /**
     * Nombre y firma del comando
     *
     * @var string
     */
    protected $signature = 'documents:compress 
                            {--months=6 : Número de meses hacia atrás para comprimir (por defecto 6 meses)}
                            {--dry-run : Solo mostrar qué se comprimiría sin hacerlo}';

    /**
     * Descripción del comando
     *
     * @var string
     */
    protected $description = 'Comprimir documentos antiguos por mes en archivos ZIP';

    /**
     * Ejecutar el comando
     *
     * @return int
     */
    public function handle()
    {
        $monthsBack = (int) $this->option('months');
        $dryRun = $this->option('dry-run');

        $this->info("🔍 Buscando documentos de hace más de {$monthsBack} meses...");

        // Calcular fecha límite (documentos creados hace más de X meses)
        $cutoffDate = Carbon::now()->subMonths($monthsBack)->startOfMonth();

        // Buscar documentos completados antes de la fecha límite
        $documents = QrFile::where('status', 'completed')
            ->where('created_at', '<', $cutoffDate)
            ->whereNotNull('final_path')
            ->get();

        if ($documents->isEmpty()) {
            $this->info("✅ No hay documentos antiguos para comprimir.");
            return 0;
        }

        $this->info("📦 Encontrados {$documents->count()} documentos para comprimir.");

        // Agrupar por mes y tipo
        $grouped = $this->groupDocumentsByMonthAndType($documents);

        $compressed = 0;
        $errors = 0;

        foreach ($grouped as $key => $group) {
            [$type, $monthYear] = explode('|', $key);
            
            if ($dryRun) {
                $this->line("  [DRY RUN] Comprimiría {$group['count']} documentos de {$type} del mes {$monthYear}");
                continue;
            }

            try {
                $result = $this->compressGroup($type, $monthYear, $group['documents']);
                if ($result) {
                    $compressed += $group['count'];
                    $this->info("✅ Comprimidos {$group['count']} documentos de {$type} del mes {$monthYear}");
                } else {
                    $errors++;
                    $this->error("❌ Error al comprimir {$type}/{$monthYear}");
                }
            } catch (\Exception $e) {
                $errors++;
                $this->error("❌ Error: " . $e->getMessage());
            }
        }

        if ($dryRun) {
            $this->warn("⚠️  Modo DRY RUN: No se comprimió nada.");
        } else {
            $this->info("✅ Proceso completado: {$compressed} documentos comprimidos, {$errors} errores.");
        }

        return 0;
    }

    /**
     * Agrupar documentos por mes y tipo
     *
     * @param \Illuminate\Database\Eloquent\Collection $documents
     * @return array
     */
    private function groupDocumentsByMonthAndType($documents): array
    {
        $grouped = [];

        foreach ($documents as $doc) {
            // Extraer tipo y mes de nueva estructura: final/{TIPO}/{YYYYMM}/{qr_id}/documento.pdf
            // O estructura antigua: final/{TIPO}/documento.pdf
            $pathParts = explode('/', $doc->final_path);
            
            $type = 'OTROS';
            $monthYear = Carbon::parse($doc->created_at)->format('Ym'); // Por defecto, mes de creación
            
            if (count($pathParts) >= 2) {
                $type = strtoupper($pathParts[1] ?? 'OTROS');
                // Nueva estructura: pathParts[2] es el año/mes (6 dígitos)
                if (count($pathParts) >= 3 && preg_match('/^(\d{6})$/', $pathParts[2] ?? '', $matches)) {
                    $monthYear = $matches[1]; // YYYYMM de la ruta
                } else {
                    // Estructura antigua: extraer mes del nombre del archivo
                    $filename = basename($doc->final_path);
                    if (preg_match('/^(\d{6})-\w+-/', $filename, $matches)) {
                        $monthYear = $matches[1];
                    }
                }
            }
            
            // Si no se pudo extraer mes de la ruta (estructura antigua), intentar del nombre
            if ($monthYear === Carbon::parse($doc->created_at)->format('Ym')) {
                // No se extrajo de la ruta, intentar del nombre del archivo
                $filename = basename($doc->final_path);
                if (preg_match('/^(\d{8})-(\d{6})-\w+-/', $filename, $matches)) {
                    // Formato antiguo: {random}-{YYYYMM}-{qr_id}-...
                    $monthYear = $matches[2];
                } elseif (preg_match('/^(\d{6})-\w+-/', $filename, $matches)) {
                    // Formato antiguo: {YYYYMM}-{qr_id}-...
                    $monthYear = $matches[1];
                }
            }

            $key = "{$type}|{$monthYear}";

            if (!isset($grouped[$key])) {
                $grouped[$key] = [
                    'type' => $type,
                    'month' => $monthYear,
                    'documents' => [],
                    'count' => 0
                ];
            }

            $grouped[$key]['documents'][] = $doc;
            $grouped[$key]['count']++;
        }

        return $grouped;
    }

    /**
     * Comprimir un grupo de documentos
     *
     * @param string $type Tipo de documento (CE, IN, SU)
     * @param string $monthYear Mes y año (YYYYMM, ej: 202511)
     * @param array $documents Documentos a comprimir
     * @return bool
     */
    private function compressGroup(string $type, string $monthYear, array $documents): bool
    {
        // Crear carpeta de archivos comprimidos (solo por tipo, sin subcarpetas mensuales)
        $archiveFolder = "archived/{$type}";
        Storage::disk('local')->makeDirectory($archiveFolder);

        // Nombre del archivo ZIP con formato: {TIPO}-{YYYYMM}.zip
        // Ejemplo: CE-202511.zip, IN-202512.zip, SU-202601.zip
        $zipFilename = "{$type}-{$monthYear}.zip";
        $zipPath = Storage::disk('local')->path("{$archiveFolder}/{$zipFilename}");

        // Crear archivo ZIP
        $zip = new ZipArchive();
        
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return false;
        }

        $added = 0;

        foreach ($documents as $doc) {
            // Agregar PDF final al ZIP
            if ($doc->final_path) {
                $finalPath = str_replace('final/', '', $doc->final_path);
                $fullPath = Storage::disk('final')->path($finalPath);
                
                if (file_exists($fullPath)) {
                    // Nombre en el ZIP: {qr_id}-{nombre_original}.pdf
                    $zipName = "{$doc->qr_id}-{$doc->original_filename}";
                    $zip->addFile($fullPath, $zipName);
                    $added++;
                }
            }

            // Agregar QR si existe
            if ($doc->qr_path) {
                $qrPath = Storage::disk('qrcodes')->path(basename($doc->qr_path));
                if (file_exists($qrPath)) {
                    $zip->addFile($qrPath, "QR-{$doc->qr_id}.png");
                }
            }
        }

        $zip->close();

        if ($added === 0) {
            // Si no se agregó nada, eliminar el ZIP vacío
            @unlink($zipPath);
            return false;
        }

        // Marcar documentos como archivados (opcional: agregar columna 'archived' a la tabla)
        // Por ahora, solo guardamos la referencia del ZIP en un campo JSON o similar
        // O simplemente los eliminamos físicamente después de comprimir

        // Eliminar PDFs finales originales (ya están en el ZIP)
        foreach ($documents as $doc) {
            if ($doc->final_path) {
                $finalPath = str_replace('final/', '', $doc->final_path);
                Storage::disk('final')->delete($finalPath);
            }
            
            // Marcar como archivado (si tienes columna archived)
            // $doc->update(['archived' => true, 'archive_path' => "{$archiveFolder}/{$zipFilename}"]);
        }

        return true;
    }
}

