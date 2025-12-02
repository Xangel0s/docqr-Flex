<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Models\QrFile;
use App\Services\QrGeneratorService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class MigrateDocumentsToQrFiles extends Command
{
    protected $signature = 'documents:migrate-to-qr 
                            {--dry-run : Ejecutar sin guardar en la base de datos}
                            {--limit= : Limitar número de documentos a migrar}
                            {--search-path= : Ruta base donde buscar archivos (ej: /var/www/uploads)}';

    protected $description = 'Migrar documentos de la tabla document a qr_files y generar QR codes';

    protected $qrGenerator;

    public function __construct(QrGeneratorService $qrGenerator)
    {
        parent::__construct();
        $this->qrGenerator = $qrGenerator;
    }

    public function handle()
    {
        $dryRun = $this->option('dry-run');
        $limit = $this->option('limit') ? (int) $this->option('limit') : null;
        $searchPath = $this->option('search-path');

        $this->info('🚀 Iniciando migración de documentos...');
        
        if ($dryRun) {
            $this->warn('⚠️  MODO DRY-RUN: No se guardarán cambios en la base de datos');
        }

        // Verificar que la tabla document existe
        if (!Schema::hasTable('document')) {
            $this->error('❌ La tabla "document" no existe en la base de datos');
            $this->info('💡 Verifica que hayas importado el archivo document.sql');
            return 1;
        }

        // Obtener documentos de la tabla antigua
        // Primero contar todos (sin filtro de is_active para diagnóstico)
        $totalAll = DB::table('document')->count();
        
        // Intentar diferentes formas de filtrar is_active (puede ser BIT(1) o TINYINT)
        $totalActive1 = DB::table('document')->where('is_active', 1)->count();
        $totalActiveBit = DB::table('document')->whereRaw('is_active = b\'1\'')->count();
        $totalActiveTrue = DB::table('document')->where('is_active', true)->count();
        
        $this->info("📊 Documentos en tabla 'document':");
        $this->line("   Total: {$totalAll}");
        $this->line("   Con is_active=1: {$totalActive1}");
        $this->line("   Con is_active=b'1': {$totalActiveBit}");
        
        if ($totalAll === 0) {
            $this->error('❌ La tabla "document" está vacía');
            $this->info('💡 Necesitas importar el archivo document.sql primero');
            $this->info('   Ejecuta: mysql -u usuario -p nombre_bd < document.sql');
            return 1;
        }
        
        // Obtener documentos - usar el filtro que funcione o traer todos
        $query = DB::table('document');
        
        // Intentar filtrar por is_active de diferentes formas
        if ($totalActive1 > 0) {
            $query->where('is_active', 1);
            $this->info("✅ Usando filtro: solo documentos activos (is_active=1)");
        } elseif ($totalActiveBit > 0) {
            $query->whereRaw('is_active = b\'1\'');
            $this->info("✅ Usando filtro: solo documentos activos (is_active=b'1')");
        } else {
            // Si no hay activos con ningún filtro, traer todos
            $this->warn("⚠️  No se encontraron documentos activos, migrando TODOS los documentos");
        }
        
        $query->orderBy('document_id');

        if ($limit) {
            $query->limit($limit);
        }

        $documents = $query->get();
        $total = $documents->count();

        $this->info("📊 Total de documentos a migrar: {$total}");

        if ($total === 0) {
            $this->warn('No se encontraron documentos para migrar');
            return 0;
        }

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $success = 0;
        $skipped = 0;
        $errors = 0;
        $filesNotFound = [];

        foreach ($documents as $doc) {
            try {
                // Verificar si ya existe en qr_files
                $existing = QrFile::where('document_id', $doc->document_id)->first();
                if ($existing) {
                    $skipped++;
                    $bar->advance();
                    continue;
                }

                // Construir folder_name: PREFIX-CODE
                $folderName = trim($doc->prefix_code . '-' . $doc->code);
                
                // Verificar si el folder_name ya existe
                $existingFolder = QrFile::where('folder_name', $folderName)->first();
                if ($existingFolder) {
                    $this->warn("\n⚠️  Folder name duplicado: {$folderName} (document_id: {$doc->document_id})");
                    $skipped++;
                    $bar->advance();
                    continue;
                }

                // Generar qr_id único (usar password_file si existe y es válido, sino generar nuevo)
                $qrId = $this->generateQrId($doc->password_file ?? null);

                // Convertir file_size de string a bytes
                $fileSizeBytes = $this->convertFileSizeToBytes($doc->file_size ?? '0 KB');

                // Buscar archivo físico
                $filePath = $this->findPhysicalFile($doc, $searchPath);

                // Generar URL del QR (en comandos usamos config para la URL base)
                $baseUrl = config('app.url', 'https://docqr-api.geofal.com.pe');
                $qrUrl = rtrim($baseUrl, '/') . "/api/view/{$qrId}";

                // Generar QR code
                $qrPath = $this->qrGenerator->generate($qrUrl, $qrId);

                if (!$dryRun) {
                    // Crear registro en qr_files
                    QrFile::create([
                        'qr_id' => $qrId,
                        'document_id' => $doc->document_id,
                        'folder_name' => $folderName,
                        'original_filename' => $doc->file_name ?? 'documento.pdf',
                        'file_path' => $filePath,
                        'qr_path' => $qrPath,
                        'final_path' => null, // Se generará después si es necesario
                        'file_size' => $fileSizeBytes,
                        'qr_position' => null,
                        'status' => $filePath ? 'uploaded' : 'failed',
                        'scan_count' => 0,
                        'last_scanned_at' => null,
                        'created_at' => $doc->creation_date ?? now(),
                        'updated_at' => $doc->update_date ?? now(),
                    ]);

                    $success++;
                } else {
                    // Modo dry-run: solo mostrar información (solo si encuentra archivo o cada 100 documentos)
                    if ($filePath || ($success % 100 === 0)) {
                        $this->line("\n📄 Documento ID: {$doc->document_id}");
                        $this->line("   Folder: {$folderName}");
                        $this->line("   QR ID: {$qrId}");
                        if ($filePath) {
                            $this->line("   ✅ Archivo: {$filePath}");
                        } else {
                            $this->line("   ❌ Archivo: NO ENCONTRADO");
                        }
                    }
                    $success++;
                }

            } catch (\Exception $e) {
                $errors++;
                Log::error("Error migrando documento {$doc->document_id}: " . $e->getMessage(), [
                    'document_id' => $doc->document_id,
                    'trace' => $e->getTraceAsString()
                ]);
                
                if (!$filePath) {
                    $filesNotFound[] = [
                        'document_id' => $doc->document_id,
                        'file_name' => $doc->file_name ?? 'N/A',
                        'folder_name' => $folderName ?? 'N/A'
                    ];
                }
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        // Resumen
        $this->info("✅ Migración completada:");
        $this->line("   ✓ Migrados: {$success}");
        $this->line("   ⊘ Omitidos: {$skipped}");
        $this->line("   ✗ Errores: {$errors}");

        if (count($filesNotFound) > 0) {
            $this->warn("\n⚠️  Archivos no encontrados: " . count($filesNotFound));
            $this->line("   Revisa el log para más detalles");
            
            // Guardar lista de archivos no encontrados
            $logFile = storage_path('logs/migration_files_not_found_' . date('Y-m-d') . '.log');
            file_put_contents($logFile, json_encode($filesNotFound, JSON_PRETTY_PRINT));
            $this->line("   Lista guardada en: {$logFile}");
        }

        return 0;
    }

    /**
     * Generar qr_id único
     */
    private function generateQrId(?string $passwordFile): string
    {
        // Si password_file existe y tiene formato válido (12 caracteres), usarlo
        if ($passwordFile && strlen($passwordFile) >= 8 && strlen($passwordFile) <= 32) {
            // Verificar que no exista
            if (!QrFile::where('qr_id', $passwordFile)->exists()) {
                return $passwordFile;
            }
        }

        // Generar nuevo qr_id único
        do {
            $qrId = Str::random(12);
        } while (QrFile::where('qr_id', $qrId)->exists());

        return $qrId;
    }

    /**
     * Convertir tamaño de archivo de string a bytes
     * Ejemplo: "723.59 KB" -> 741136
     */
    private function convertFileSizeToBytes(string $fileSize): int
    {
        $fileSize = trim($fileSize);
        
        // Extraer número y unidad
        if (preg_match('/^([\d.]+)\s*(KB|MB|GB|bytes?)?$/i', $fileSize, $matches)) {
            $number = (float) $matches[1];
            $unit = strtoupper($matches[2] ?? 'KB');

            switch ($unit) {
                case 'GB':
                    return (int) ($number * 1024 * 1024 * 1024);
                case 'MB':
                    return (int) ($number * 1024 * 1024);
                case 'KB':
                    return (int) ($number * 1024);
                case 'BYTES':
                case 'BYTE':
                default:
                    return (int) $number;
            }
        }

        return 0;
    }

    /**
     * Buscar archivo físico en el servidor
     */
    private function findPhysicalFile($document, ?string $searchPath = null): ?string
    {
        $fileName = $document->file_name ?? null;
        
        if (!$fileName) {
            return null;
        }

        // Si searchPath es "..." o está vacío, usar la ruta por defecto
        if (!$searchPath || $searchPath === '...') {
            $searchPath = storage_path('app/uploads');
        }

        $basePath = rtrim($searchPath, '/');
        $documentType = $this->extractDocumentType($document->prefix_code ?? '');
        
        // Limpiar nombre de archivo para búsqueda (sin caracteres especiales problemáticos)
        $cleanFileName = basename($fileName);
        $fileNameWithoutExt = pathinfo($cleanFileName, PATHINFO_FILENAME);
        
        // Obtener código del documento y password_file para buscar en subcarpetas
        $documentCode = trim($document->code ?? '');
        $passwordFile = $document->password_file ?? '';
        
        // 0. PRIMERO: Buscar en carpeta "document" dentro de uploads
        $documentPaths = [
            storage_path('app/uploads/document'),
            storage_path('app/document'),
            $basePath . '/document',
        ];
        
        foreach ($documentPaths as $docPath) {
            if (is_dir($docPath)) {
                // Buscar en estructura: document/TIPO/YYYYMM/CODIGO/archivo.pdf
                if ($documentType && $documentType !== 'OTROS') {
                    $typeDocPath = $docPath . '/' . $documentType;
                    if (is_dir($typeDocPath)) {
                        // Obtener fecha del documento
                        $creationDate = $document->creation_date ?? now();
                        $yearMonth = is_string($creationDate) 
                            ? date('Ym', strtotime($creationDate)) 
                            : (is_object($creationDate) ? $creationDate->format('Ym') : date('Ym'));
                        $yearMonthDash = is_string($creationDate) 
                            ? date('Y-m', strtotime($creationDate)) 
                            : (is_object($creationDate) ? $creationDate->format('Y-m') : date('Y-m'));
                        
                        // Buscar en carpetas de fecha
                        $dateFolders = [$yearMonth, $yearMonthDash];
                        foreach ($dateFolders as $dateFolder) {
                            $dateDocPath = $typeDocPath . '/' . $dateFolder;
                            if (is_dir($dateDocPath)) {
                                // Buscar por código
                                if ($documentCode) {
                                    $codeDocPath = $dateDocPath . '/' . $documentCode;
                                    if (is_dir($codeDocPath)) {
                                        $pdfs = glob($codeDocPath . '/*.pdf');
                                        if (!empty($pdfs)) {
                                            return $this->getRelativePath($pdfs[0]);
                                        }
                                    }
                                    
                                    // Buscar por coincidencia parcial
                                    $subdirs = glob($dateDocPath . '/*', GLOB_ONLYDIR);
                                    foreach ($subdirs as $subdir) {
                                        $subdirName = basename($subdir);
                                        $normalizedCode = preg_replace('/[^a-zA-Z0-9-]/', '', strtolower($documentCode));
                                        $normalizedSubdir = preg_replace('/[^a-zA-Z0-9-]/', '', strtolower($subdirName));
                                        
                                        if (stripos($normalizedSubdir, $normalizedCode) !== false || 
                                            stripos($normalizedCode, $normalizedSubdir) !== false ||
                                            stripos($subdirName, $documentCode) !== false ||
                                            stripos($documentCode, $subdirName) !== false) {
                                            $pdfs = glob($subdir . '/*.pdf');
                                            if (!empty($pdfs)) {
                                                return $this->getRelativePath($pdfs[0]);
                                            }
                                        }
                                    }
                                }
                                
                                // Buscar directamente en la carpeta de fecha (por si los PDFs están ahí)
                                $found = $this->searchFileRecursive($dateDocPath, $cleanFileName);
                                if ($found) {
                                    return $this->getRelativePath($found);
                                }
                                
                                $found = $this->searchFileRecursive($dateDocPath, $fileNameWithoutExt, true);
                                if ($found) {
                                    return $this->getRelativePath($found);
                                }
                            }
                        }
                        
                        // Buscar en TODAS las carpetas de fecha dentro del tipo
                        $allDateFolders = scandir($typeDocPath);
                        $dateFolders = array_filter($allDateFolders, function($folder) {
                            return $folder !== '.' && $folder !== '..' && 
                                   (preg_match('/^\d{4}-?\d{2}$/', $folder) || preg_match('/^\d{6}$/', $folder));
                        });
                        
                        foreach ($dateFolders as $dateFolder) {
                            $dateDocPath = $typeDocPath . '/' . $dateFolder;
                            if (is_dir($dateDocPath)) {
                                // Buscar por código
                                if ($documentCode) {
                                    $codeDocPath = $dateDocPath . '/' . $documentCode;
                                    if (is_dir($codeDocPath)) {
                                        $pdfs = glob($codeDocPath . '/*.pdf');
                                        if (!empty($pdfs)) {
                                            return $this->getRelativePath($pdfs[0]);
                                        }
                                    }
                                    
                                    // Buscar por coincidencia parcial
                                    $subdirs = glob($dateDocPath . '/*', GLOB_ONLYDIR);
                                    foreach ($subdirs as $subdir) {
                                        $subdirName = basename($subdir);
                                        $normalizedCode = preg_replace('/[^a-zA-Z0-9-]/', '', strtolower($documentCode));
                                        $normalizedSubdir = preg_replace('/[^a-zA-Z0-9-]/', '', strtolower($subdirName));
                                        
                                        if (stripos($normalizedSubdir, $normalizedCode) !== false || 
                                            stripos($normalizedCode, $normalizedSubdir) !== false ||
                                            stripos($subdirName, $documentCode) !== false ||
                                            stripos($documentCode, $subdirName) !== false) {
                                            $pdfs = glob($subdir . '/*.pdf');
                                            if (!empty($pdfs)) {
                                                return $this->getRelativePath($pdfs[0]);
                                            }
                                        }
                                    }
                                }
                                
                                // Buscar directamente en la carpeta de fecha
                                $found = $this->searchFileRecursive($dateDocPath, $cleanFileName);
                                if ($found) {
                                    return $this->getRelativePath($found);
                                }
                                
                                $found = $this->searchFileRecursive($dateDocPath, $fileNameWithoutExt, true);
                                if ($found) {
                                    return $this->getRelativePath($found);
                                }
                            }
                        }
                    }
                }
                
                // Buscar recursivamente en toda la carpeta document
                $found = $this->searchFileRecursive($docPath, $cleanFileName);
                if ($found) {
                    return $this->getRelativePath($found);
                }
                
                $found = $this->searchFileRecursive($docPath, $fileNameWithoutExt, true);
                if ($found) {
                    return $this->getRelativePath($found);
                }
            }
        }
        
        // 1. Buscar en estructura: TIPO/YYYYMM/CODIGO/archivo.pdf
        if ($documentType && $documentType !== 'OTROS') {
            $typePath = $basePath . '/' . $documentType;
            if (is_dir($typePath)) {
                // Obtener fecha del documento
                $creationDate = $document->creation_date ?? now();
                $yearMonth = is_string($creationDate) 
                    ? date('Ym', strtotime($creationDate)) 
                    : (is_object($creationDate) ? $creationDate->format('Ym') : date('Ym'));
                $yearMonthDash = is_string($creationDate) 
                    ? date('Y-m', strtotime($creationDate)) 
                    : (is_object($creationDate) ? $creationDate->format('Y-m') : date('Y-m'));
                
                // Buscar en carpetas de fecha dentro del tipo (incluyendo año anterior/siguiente por si hay desfase)
                $dateFolders = [
                    $yearMonth,           // 202408
                    $yearMonthDash,       // 2024-08
                    substr($yearMonth, 0, 4) . '-' . substr($yearMonth, 4, 2), // 2024-08 (alternativo)
                ];
                
                // También buscar en año siguiente (por si los archivos se movieron)
                $nextYear = (int)substr($yearMonth, 0, 4) + 1;
                $nextYearMonth = $nextYear . substr($yearMonth, 4);
                $nextYearMonthDash = $nextYear . '-' . substr($yearMonth, 4, 2);
                $dateFolders[] = $nextYearMonth;
                $dateFolders[] = $nextYearMonthDash;
                
                foreach ($dateFolders as $dateFolder) {
                    $datePath = $typePath . '/' . $dateFolder;
                    if (is_dir($datePath)) {
                        // Buscar en subcarpeta con código del documento
                        if ($documentCode) {
                            // Buscar exacto
                            $codePath = $datePath . '/' . $documentCode;
                            if (is_dir($codePath)) {
                                $pdfs = glob($codePath . '/*.pdf');
                                if (!empty($pdfs)) {
                                    return $this->getRelativePath($pdfs[0]);
                                }
                            }
                            
                            // Buscar por coincidencia parcial en nombres de carpetas
                            $subdirs = glob($datePath . '/*', GLOB_ONLYDIR);
                            foreach ($subdirs as $subdir) {
                                $subdirName = basename($subdir);
                                // Normalizar para comparar (remover espacios, caracteres especiales)
                                $normalizedCode = preg_replace('/[^a-zA-Z0-9-]/', '', strtolower($documentCode));
                                $normalizedSubdir = preg_replace('/[^a-zA-Z0-9-]/', '', strtolower($subdirName));
                                
                                // Si el código está contenido en el nombre de la carpeta o viceversa
                                if (stripos($normalizedSubdir, $normalizedCode) !== false || 
                                    stripos($normalizedCode, $normalizedSubdir) !== false ||
                                    stripos($subdirName, $documentCode) !== false ||
                                    stripos($documentCode, $subdirName) !== false) {
                                    $pdfs = glob($subdir . '/*.pdf');
                                    if (!empty($pdfs)) {
                                        return $this->getRelativePath($pdfs[0]);
                                    }
                                }
                            }
                        }
                        
                        // Buscar por password_file (QR ID)
                        if ($passwordFile) {
                            $qrPath = $datePath . '/' . $passwordFile;
                            if (is_dir($qrPath)) {
                                $pdfs = glob($qrPath . '/*.pdf');
                                if (!empty($pdfs)) {
                                    return $this->getRelativePath($pdfs[0]);
                                }
                            }
                        }
                        
                        // Buscar por nombre de archivo en toda la carpeta de fecha (recursivo)
                        $found = $this->searchFileRecursive($datePath, $cleanFileName);
                        if ($found) {
                            return $this->getRelativePath($found);
                        }
                        
                        // Buscar por nombre parcial del archivo
                        $found = $this->searchFileRecursive($datePath, $fileNameWithoutExt, true);
                        if ($found) {
                            return $this->getRelativePath($found);
                        }
                        
                        // Buscar por parte del nombre del archivo (ej: "072-24" de "F-1-InformeN°072-24AG19...")
                        if (preg_match('/(\d+-\d+)/', $cleanFileName, $matches)) {
                            $numberPart = $matches[1];
                            $found = $this->searchFileRecursive($datePath, $numberPart, true);
                            if ($found) {
                                return $this->getRelativePath($found);
                            }
                        }
                    }
                }
                
                // Buscar en TODAS las carpetas de fecha dentro del tipo
                $allDateFolders = scandir($typePath);
                $dateFolders = array_filter($allDateFolders, function($folder) {
                    return $folder !== '.' && $folder !== '..' && 
                           (preg_match('/^\d{4}-?\d{2}$/', $folder) || preg_match('/^\d{6}$/', $folder));
                });
                
                foreach ($dateFolders as $dateFolder) {
                    $datePath = $typePath . '/' . $dateFolder;
                    if (is_dir($datePath)) {
                        // Buscar por código
                        if ($documentCode) {
                            // Buscar exacto
                            $codePath = $datePath . '/' . $documentCode;
                            if (is_dir($codePath)) {
                                $pdfs = glob($codePath . '/*.pdf');
                                if (!empty($pdfs)) {
                                    return $this->getRelativePath($pdfs[0]);
                                }
                            }
                            
                            // Buscar por coincidencia parcial
                            $subdirs = glob($datePath . '/*', GLOB_ONLYDIR);
                            foreach ($subdirs as $subdir) {
                                $subdirName = basename($subdir);
                                $normalizedCode = preg_replace('/[^a-zA-Z0-9-]/', '', strtolower($documentCode));
                                $normalizedSubdir = preg_replace('/[^a-zA-Z0-9-]/', '', strtolower($subdirName));
                                
                                if (stripos($normalizedSubdir, $normalizedCode) !== false || 
                                    stripos($normalizedCode, $normalizedSubdir) !== false ||
                                    stripos($subdirName, $documentCode) !== false ||
                                    stripos($documentCode, $subdirName) !== false) {
                                    $pdfs = glob($subdir . '/*.pdf');
                                    if (!empty($pdfs)) {
                                        return $this->getRelativePath($pdfs[0]);
                                    }
                                }
                            }
                        }
                        
                        // Buscar recursivamente por nombre de archivo
                        $found = $this->searchFileRecursive($datePath, $cleanFileName);
                        if ($found) {
                            return $this->getRelativePath($found);
                        }
                        
                        $found = $this->searchFileRecursive($datePath, $fileNameWithoutExt, true);
                        if ($found) {
                            return $this->getRelativePath($found);
                        }
                        
                        // Buscar por parte numérica del nombre (ej: "072-24")
                        if (preg_match('/(\d+-\d+)/', $cleanFileName, $matches)) {
                            $numberPart = $matches[1];
                            $found = $this->searchFileRecursive($datePath, $numberPart, true);
                            if ($found) {
                                return $this->getRelativePath($found);
                            }
                        }
                    }
                }
            }
        }
        
        // 2. Buscar en la carpeta de fecha específica del documento (por si acaso)
        $creationDate = $document->creation_date ?? now();
        $yearMonth = is_string($creationDate) 
            ? date('Ym', strtotime($creationDate)) 
            : (is_object($creationDate) ? $creationDate->format('Ym') : date('Ym'));
        $yearMonthDash = is_string($creationDate) 
            ? date('Y-m', strtotime($creationDate)) 
            : (is_object($creationDate) ? $creationDate->format('Y-m') : date('Y-m'));
        
        $specificDateFolders = [
            $yearMonth,           // 202507
            $yearMonthDash,       // 2025-07
            substr($yearMonth, 0, 4) . '-' . substr($yearMonth, 4, 2), // 2025-07 (alternativo)
        ];
        
        foreach ($specificDateFolders as $dateFolder) {
            $datePath = $basePath . '/' . $dateFolder;
            if (is_dir($datePath)) {
                $found = $this->searchFileRecursive($datePath, $cleanFileName);
                if ($found) {
                    return $this->getRelativePath($found);
                }
                
                $found = $this->searchFileRecursive($datePath, $fileNameWithoutExt, true);
                if ($found) {
                    return $this->getRelativePath($found);
                }
            }
        }
        
        // 2. Buscar en carpetas de tipo directamente (CE, IN, SU) - por si están en la raíz
        if ($documentType && $documentType !== 'OTROS') {
            $typePath = $basePath . '/' . $documentType;
            if (is_dir($typePath)) {
                $found = $this->searchFileRecursive($typePath, $cleanFileName);
                if ($found) {
                    return $this->getRelativePath($found);
                }
                
                $found = $this->searchFileRecursive($typePath, $fileNameWithoutExt, true);
                if ($found) {
                    return $this->getRelativePath($found);
                }
            }
        }
        
        // 3. Buscar en toda la carpeta uploads recursivamente (último recurso)
        if (is_dir($basePath)) {
            // Buscar por nombre exacto
            $found = $this->searchFileRecursive($basePath, $cleanFileName);
            if ($found) {
                return $this->getRelativePath($found);
            }
            
            // Buscar por nombre parcial
            $found = $this->searchFileRecursive($basePath, $fileNameWithoutExt, true);
            if ($found) {
                return $this->getRelativePath($found);
            }
            
            // Buscar por parte numérica del nombre (ej: "072-24" de "F-1-InformeN°072-24AG19...")
            if (preg_match('/(\d+-\d+)/', $cleanFileName, $matches)) {
                $numberPart = $matches[1];
                $found = $this->searchFileRecursive($basePath, $numberPart, true);
                if ($found) {
                    return $this->getRelativePath($found);
                }
            }
            
            // Buscar por parte del código en el nombre del archivo
            if ($documentCode && preg_match('/(\d+-\d+)/', $documentCode, $codeMatches)) {
                $codeNumberPart = $codeMatches[1];
                $found = $this->searchFileRecursive($basePath, $codeNumberPart, true);
                if ($found) {
                    return $this->getRelativePath($found);
                }
            }
        }
        
        return null;
    }
    
    /**
     * Buscar archivo recursivamente en un directorio
     */
    private function searchFileRecursive(string $directory, string $searchTerm, bool $partial = false): ?string
    {
        if (!is_dir($directory)) {
            return null;
        }
        
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'pdf') {
                $fileName = $file->getFilename();
                
                if ($partial) {
                    // Búsqueda parcial: el término debe estar en el nombre
                    if (stripos($fileName, $searchTerm) !== false) {
                        return $file->getPathname();
                    }
                } else {
                    // Búsqueda exacta
                    if (strcasecmp($fileName, $searchTerm) === 0) {
                        return $file->getPathname();
                    }
                }
            }
        }
        
        return null;
    }
    
    /**
     * Convertir ruta absoluta a relativa para storage
     */
    private function getRelativePath(string $absolutePath): string
    {
        $storagePath = storage_path('app/');
        
        if (strpos($absolutePath, $storagePath) === 0) {
            return str_replace($storagePath, '', $absolutePath);
        }
        
        // Si no está en storage/app, intentar extraer la parte relevante
        if (preg_match('/uploads\/(.+)$/', $absolutePath, $matches)) {
            return 'uploads/' . $matches[1];
        }
        
        return basename($absolutePath);
    }

    /**
     * Extraer tipo de documento del prefix_code
     */
    private function extractDocumentType(?string $prefixCode): ?string
    {
        $prefixCode = strtoupper(trim($prefixCode ?? ''));
        
        $allowedTypes = ['CE', 'IN', 'SU'];
        
        if (in_array($prefixCode, $allowedTypes)) {
            return $prefixCode;
        }
        
        return 'OTROS';
    }
}