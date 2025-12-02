<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\QrFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;

class FixFilePaths extends Command
{
    protected $signature = 'files:fix-paths 
                            {--dry-run : Ejecutar sin hacer cambios}
                            {--limit= : Limitar cantidad de registros a procesar}';

    protected $description = 'Corrige las rutas de archivos en la base de datos buscando físicamente los PDFs';

    public function handle()
    {
        $dryRun = $this->option('dry-run');
        $limit = $this->option('limit');

        $this->info('🔍 Iniciando corrección de rutas de archivos...');
        $this->info($dryRun ? '⚠️  MODO DRY-RUN: No se harán cambios' : '✅ MODO REAL: Se actualizarán registros');
        $this->newLine();

        $query = QrFile::whereNotNull('qr_id');
        
        if ($limit) {
            $query->limit((int)$limit);
        }

        $totalFiles = $query->count();
        $this->info("📊 Total de archivos a procesar: {$totalFiles}");
        $this->newLine();

        $bar = $this->output->createProgressBar($totalFiles);
        $bar->start();

        $stats = [
            'procesados' => 0,
            'correctos' => 0,
            'corregidos' => 0,
            'no_encontrados' => 0,
            'errores' => 0,
        ];

        $query->chunk(100, function ($files) use (&$stats, $bar, $dryRun) {
            foreach ($files as $file) {
                $stats['procesados']++;

                try {
                    $result = $this->fixFilePath($file, $dryRun);
                    
                    if ($result['status'] === 'correcto') {
                        $stats['correctos']++;
                    } elseif ($result['status'] === 'corregido') {
                        $stats['corregidos']++;
                        $this->newLine();
                        $this->info("✅ Corregido: {$file->qr_id}");
                        $this->line("   Ruta antigua: {$result['old_path']}");
                        $this->line("   Ruta nueva:   {$result['new_path']}");
                    } elseif ($result['status'] === 'no_encontrado') {
                        $stats['no_encontrados']++;
                    }

                } catch (\Exception $e) {
                    $stats['errores']++;
                    $this->newLine();
                    $this->error("❌ Error en {$file->qr_id}: " . $e->getMessage());
                }

                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);

        // Mostrar resumen
        $this->info('═══════════════════════════════════════');
        $this->info('📊 RESUMEN DE LA CORRECCIÓN');
        $this->info('═══════════════════════════════════════');
        $this->table(
            ['Estado', 'Cantidad'],
            [
                ['Procesados', $stats['procesados']],
                ['Correctos (no necesitaban cambio)', $stats['correctos']],
                ['Corregidos', $stats['corregidos']],
                ['No encontrados', $stats['no_encontrados']],
                ['Errores', $stats['errores']],
            ]
        );

        if ($dryRun && $stats['corregidos'] > 0) {
            $this->newLine();
            $this->warn('⚠️  Ejecuta sin --dry-run para aplicar los cambios');
        }

        return 0;
    }

    private function fixFilePath(QrFile $file, bool $dryRun): array
    {
        if (!$file->file_path) {
            $foundPath = $this->searchPdfFile($file);
            if ($foundPath) {
                if (!$dryRun) {
                    $file->file_path = $foundPath;
                    $file->save();
                }
                return [
                    'status' => 'corregido',
                    'old_path' => null,
                    'new_path' => $foundPath,
                ];
            }
            return ['status' => 'no_encontrado'];
        }

        // Verificar si la ruta actual es válida
        $currentFullPath = Storage::disk('local')->path($file->file_path);
        if (file_exists($currentFullPath) && is_file($currentFullPath)) {
            return ['status' => 'correcto'];
        }

        $foundPath = $this->searchPdfFile($file);
        if ($foundPath) {
            if (!$dryRun) {
                $oldPath = $file->file_path;
                $file->file_path = $foundPath;
                $file->save();
            }
            return [
                'status' => 'corregido',
                'old_path' => $file->file_path,
                'new_path' => $foundPath,
            ];
        }

        return ['status' => 'no_encontrado'];
    }

    private function searchPdfFile(QrFile $file): ?string
    {
        $uploadsDir = storage_path('app/uploads');
        
        if (!is_dir($uploadsDir)) {
            return null;
        }

        // Buscar por qr_id en el nombre del archivo
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($uploadsDir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $fileInfo) {
            if (!$fileInfo->isFile() || $fileInfo->getExtension() !== 'pdf') {
                continue;
            }

            $filename = $fileInfo->getFilename();
            
            // Buscar por qr_id en el nombre
            if (str_contains($filename, $file->qr_id)) {
                // Obtener ruta relativa desde storage/app
                $fullPath = $fileInfo->getPathname();
                $relativePath = str_replace(storage_path('app/'), '', $fullPath);
                return $relativePath;
            }

            // Buscar por original_filename
            if ($file->original_filename && str_contains($filename, $file->original_filename)) {
                $fullPath = $fileInfo->getPathname();
                $relativePath = str_replace(storage_path('app/'), '', $fullPath);
                return $relativePath;
            }
        }

        return null;
    }
}

