<?php

namespace App\Console\Commands;

use App\Models\QrFile;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Comando para eliminar permanentemente documentos eliminados (soft delete) después de X días
 * 
 * Uso: php artisan documents:force-delete-old [--days=30]
 * 
 * Este comando elimina permanentemente (forceDelete) todos los documentos
 * que fueron eliminados con soft delete hace más de X días (por defecto 30 días)
 */
class ForceDeleteOldDocuments extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'documents:force-delete-old {--days=30 : Días después de los cuales eliminar permanentemente}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Eliminar permanentemente documentos eliminados (soft delete) después de X días';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $days = (int) $this->option('days');
        $cutoffDate = now()->subDays($days);

        $this->info("Buscando documentos eliminados antes de {$cutoffDate->format('Y-m-d H:i:s')}...");

        // Obtener documentos eliminados antes de la fecha límite
        $deletedDocuments = QrFile::onlyTrashed()
            ->where('deleted_at', '<=', $cutoffDate)
            ->get();

        if ($deletedDocuments->isEmpty()) {
            $this->info('No hay documentos eliminados para limpiar.');
            return Command::SUCCESS;
        }

        $this->info("Encontrados {$deletedDocuments->count()} documentos para eliminar permanentemente.");

        if (!$this->confirm('¿Desea continuar con la eliminación permanente?', true)) {
            $this->info('Operación cancelada.');
            return Command::SUCCESS;
        }

        $deletedCount = 0;
        $errorCount = 0;
        $bar = $this->output->createProgressBar($deletedDocuments->count());
        $bar->start();

        foreach ($deletedDocuments as $document) {
            try {
                DB::transaction(function () use ($document, &$deletedCount) {
                    // Eliminar archivos físicos
                    if ($document->file_path) {
                        try {
                            if (Storage::disk('local')->exists($document->file_path)) {
                                Storage::disk('local')->delete($document->file_path);
                            }
                            // Eliminar directorio si está vacío
                            $fileDir = dirname($document->file_path);
                            if ($fileDir && Storage::disk('local')->exists($fileDir)) {
                                $files = Storage::disk('local')->files($fileDir);
                                if (empty($files)) {
                                    Storage::disk('local')->deleteDirectory($fileDir);
                                }
                            }
                        } catch (\Exception $e) {
                            Log::warning('Error al eliminar archivo PDF en force delete:', [
                                'file_path' => $document->file_path,
                                'error' => $e->getMessage()
                            ]);
                        }
                    }
                    
                    if ($document->qr_path) {
                        try {
                            $qrFilename = basename($document->qr_path);
                            if (Storage::disk('qrcodes')->exists($qrFilename)) {
                                Storage::disk('qrcodes')->delete($qrFilename);
                            }
                        } catch (\Exception $e) {
                            Log::warning('Error al eliminar QR en force delete:', [
                                'qr_path' => $document->qr_path,
                                'error' => $e->getMessage()
                            ]);
                        }
                    }
                    
                    if ($document->final_path) {
                        try {
                            $finalPath = str_replace('final/', '', $document->final_path);
                            if (Storage::disk('final')->exists($finalPath)) {
                                Storage::disk('final')->delete($finalPath);
                            }
                            // Eliminar directorio si está vacío
                            $finalDir = dirname($finalPath);
                            if ($finalDir && Storage::disk('final')->exists($finalDir)) {
                                $files = Storage::disk('final')->files($finalDir);
                                if (empty($files)) {
                                    Storage::disk('final')->deleteDirectory($finalDir);
                                }
                            }
                        } catch (\Exception $e) {
                            Log::warning('Error al eliminar PDF final en force delete:', [
                                'final_path' => $document->final_path,
                                'error' => $e->getMessage()
                            ]);
                        }
                    }
                    
                    // Eliminar permanentemente de la base de datos
                    $document->forceDelete();
                    $deletedCount++;
                });
            } catch (\Exception $e) {
                $errorCount++;
                Log::error('Error al eliminar permanentemente documento:', [
                    'qr_id' => $document->qr_id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            }
            
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("✅ Documentos eliminados permanentemente: {$deletedCount}");
        if ($errorCount > 0) {
            $this->warn("⚠️  Errores: {$errorCount}");
        }

        return Command::SUCCESS;
    }
}

