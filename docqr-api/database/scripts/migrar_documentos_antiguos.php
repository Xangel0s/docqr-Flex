<?php

/**
 * Script de Migración de Documentos Antiguos
 * 
 * Este script migra documentos de la tabla antigua 'document' a la nueva tabla 'qr_files'.
 * 
 * IMPORTANTE:
 * - Ejecutar este script DESPUÉS de haber migrado los archivos físicos al nuevo servidor
 * - Hacer backup de la base de datos antes de ejecutar
 * - Verificar que los archivos físicos existan en las rutas especificadas
 * 
 * USO:
 * php artisan tinker
 * require 'database/scripts/migrar_documentos_antiguos.php';
 * migrarDocumentosAntiguos();
 */

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Models\QrFile;
use App\Services\QrGeneratorService;
use App\Helpers\UrlHelper;

/**
 * Función principal de migración
 */
function migrarDocumentosAntiguos()
{
    echo "\n=== MIGRACIÓN DE DOCUMENTOS ANTIGUOS ===\n\n";
    
    // Verificar que la tabla 'document' existe
    if (!DB::getSchemaBuilder()->hasTable('document')) {
        echo "❌ ERROR: La tabla 'document' no existe en la base de datos.\n";
        echo "   Si ya migraste todos los datos, puedes ignorar este mensaje.\n\n";
        return;
    }
    
    // Obtener todos los documentos antiguos activos
    // La tabla document antigua usa is_active (bit) - puede ser 1, b'1', o true
    // Intentar diferentes formas de comparar el campo bit
    $documentosAntiguos = DB::table('document')
        ->where(function($query) {
            $query->where('is_active', '=', 1)
                  ->orWhere('is_active', '=', DB::raw("b'1'"))
                  ->orWhere('is_active', '=', true);
        })
        ->get();
    
    if ($documentosAntiguos->isEmpty()) {
        echo "✅ No hay documentos antiguos para migrar.\n\n";
        return;
    }
    
    echo "📋 Encontrados " . $documentosAntiguos->count() . " documentos antiguos.\n\n";
    
    $migrados = 0;
    $errores = 0;
    $qrGenerator = new QrGeneratorService();
    
    foreach ($documentosAntiguos as $doc) {
        try {
            $docId = $doc->document_id;
            $fileName = $doc->file_name ?? 'documento_' . $docId . '.pdf';
            
            echo "🔄 Migrando documento ID: {$docId} - {$fileName}...\n";
            
            // Verificar si ya existe en qr_files (por document_id)
            $existe = QrFile::where('document_id', $docId)->first();
            
            // Generar qr_id único si no existe
            $qrId = null;
            if (isset($doc->qr_id) && !empty($doc->qr_id)) {
                // Verificar que el qr_id no esté en uso
                $qrIdExistente = QrFile::where('qr_id', $doc->qr_id)->first();
                if (!$qrIdExistente) {
                    $qrId = $doc->qr_id;
                }
            }
            
            if (!$qrId) {
                // Generar nuevo qr_id único
                do {
                    $qrId = Str::random(32);
                } while (QrFile::where('qr_id', $qrId)->exists());
            }
            
            // Mapear datos de la tabla antigua a la nueva
            // La tabla antigua tiene: prefix_code (CE, IN, SU) y code (código del documento)
            // También puede tener folder_name directamente
            $prefixCode = $doc->prefix_code ?? 'MIG';
            $code = $doc->code ?? $docId;
            
            // Si ya tiene folder_name, usarlo; si no, construirlo
            $folderName = isset($doc->folder_name) && !empty($doc->folder_name) 
                ? $doc->folder_name 
                : ($prefixCode . '-' . $code); // Ej: CE-240804.01
            
            $originalFilename = $doc->file_name ?? 'documento_' . $docId . '.pdf';
            
            // Rutas de archivos (ajustar según estructura antigua)
            $filePath = null;
            $qrPath = null;
            $finalPath = null;
            
            // Intentar encontrar el archivo PDF original
            // La estructura antigua es: uploads/document/{TIPO}/{YYYYMM}/{código}/archivo.pdf
            // Ejemplo: uploads/document/IN/202507/N-072-24-AG19/archivo.pdf
            
            // Extraer año y mes de creation_date para construir la ruta
            $yearMonth = null;
            if (isset($doc->creation_date) && !empty($doc->creation_date)) {
                try {
                    $date = new \DateTime($doc->creation_date);
                    $yearMonth = $date->format('Ym'); // 202507, 202508, etc.
                } catch (\Exception $e) {
                    // Si falla, intentar extraer de otra forma
                }
            }
            
            // Si no se pudo extraer, usar el año/mes actual como fallback
            if (!$yearMonth) {
                $yearMonth = date('Ym');
            }
            
            // Construir rutas posibles basadas en la estructura antigua
            $posiblesRutas = [];
            
            // 1. Ruta directa si existe file_path
            if (isset($doc->file_path) && !empty($doc->file_path)) {
                $posiblesRutas[] = $doc->file_path;
                // También sin "storage/" si viene con ese prefijo
                $posiblesRutas[] = str_replace('storage/', '', $doc->file_path);
                $posiblesRutas[] = str_replace('storage/app/', '', $doc->file_path);
            }
            
            // 2. Estructura antigua: uploads/document/{TIPO}/{YYYYMM}/{código}/archivo.pdf
            // Buscar en TODOS los meses posibles, no solo en el mes de creación
            if ($prefixCode && $code) {
                // El código puede tener espacios y caracteres especiales, limpiarlo pero también buscar variaciones
                $codigoLimpio = trim($code);
                $codigoSinEspacios = preg_replace('/\s+/', '', $codigoLimpio);
                $codigoSinEspeciales = preg_replace('/[^a-zA-Z0-9\-_\.]/', '', $codigoLimpio);
                
                $codigosBuscar = array_unique([$codigoLimpio, $codigoSinEspacios, $codigoSinEspeciales]);
                
                // Buscar en TODOS los meses de ese tipo de documento
                // Los archivos están en uploads/{TIPO}/{YYYYMM}/{código}/ (sin "document/")
                $tipoPaths = [
                    "uploads/{$prefixCode}",  // Estructura actual (sin document/)
                    "uploads/document/{$prefixCode}",  // Estructura antigua (con document/)
                ];
                
                foreach ($tipoPaths as $tipoPath) {
                    if (Storage::disk('local')->exists($tipoPath)) {
                        // Obtener todas las carpetas de año/mes
                        $meses = Storage::disk('local')->directories($tipoPath);
                        foreach ($meses as $mesPath) {
                            // Buscar con cada variación del código
                            foreach ($codigosBuscar as $codigoVariante) {
                                if (empty($codigoVariante)) continue;
                                
                                $codigoPath = $mesPath . '/' . $codigoVariante;
                                if (Storage::disk('local')->exists($codigoPath)) {
                                    $archivos = Storage::disk('local')->files($codigoPath);
                                    foreach ($archivos as $archivo) {
                                        if (strtolower(pathinfo($archivo, PATHINFO_EXTENSION)) === 'pdf') {
                                            $posiblesRutas[] = $archivo;
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
                
                // También buscar por password_file (hash del archivo) en todas las carpetas
                if (isset($doc->password_file) && !empty($doc->password_file)) {
                    $passwordFile = $doc->password_file;
                    foreach ($tipoPaths as $tipoPath) {
                        if (Storage::disk('local')->exists($tipoPath)) {
                            $archivos = Storage::disk('local')->allFiles($tipoPath);
                            foreach ($archivos as $archivo) {
                                $nombreArchivo = basename($archivo);
                                // Buscar por password_file en el nombre del archivo
                                if (strpos($nombreArchivo, $passwordFile) !== false) {
                                    $posiblesRutas[] = $archivo;
                                }
                            }
                        }
                    }
                }
            }
            
            // 3. Buscar por nombre de archivo en toda la estructura (sin restricción de año/mes)
            if (!$filePath) {
                $nombreArchivo = basename($originalFilename);
                // Buscar en uploads/{TIPO}/ y uploads/document/{TIPO}/ (todos los meses)
                if ($prefixCode) {
                    $basePaths = [
                        "uploads/{$prefixCode}",
                        "uploads/document/{$prefixCode}"
                    ];
                    foreach ($basePaths as $basePath) {
                        if (Storage::disk('local')->exists($basePath)) {
                            // Buscar recursivamente en todas las carpetas de año/mes
                            $archivos = Storage::disk('local')->allFiles($basePath);
                            foreach ($archivos as $archivo) {
                                $nombreArchivoBase = basename($archivo);
                                // Coincidencia exacta o parcial del nombre
                                if ($nombreArchivoBase === $nombreArchivo || 
                                    strpos($nombreArchivoBase, $nombreArchivo) !== false ||
                                    strpos($nombreArchivo, $nombreArchivoBase) !== false) {
                                    $posiblesRutas[] = $archivo;
                                }
                            }
                        }
                    }
                }
            }
            
            // 4. Buscar por password_file en toda la estructura uploads
            if (!$filePath && isset($doc->password_file) && !empty($doc->password_file)) {
                $passwordFile = $doc->password_file;
                // Buscar recursivamente en uploads y uploads/document (todos los tipos y meses)
                $basePaths = ['uploads', 'uploads/document'];
                foreach ($basePaths as $basePath) {
                    if (Storage::disk('local')->exists($basePath)) {
                        $archivos = Storage::disk('local')->allFiles($basePath);
                        foreach ($archivos as $archivo) {
                            $nombreArchivo = basename($archivo);
                            $nombreSinExtension = pathinfo($nombreArchivo, PATHINFO_FILENAME);
                            // Si el nombre del archivo contiene el password_file o viceversa
                            if (strpos($nombreArchivo, $passwordFile) !== false || 
                                strpos($passwordFile, $nombreSinExtension) !== false ||
                                strpos($nombreSinExtension, $passwordFile) !== false) {
                                $posiblesRutas[] = $archivo;
                            }
                        }
                    }
                }
            }
            
            // 5. Buscar por código en toda la estructura (último recurso)
            if (!$filePath && $code) {
                $codigoLimpio = preg_replace('/[^a-zA-Z0-9\-_\.]/', '', $code);
                $codigoLimpio = trim($codigoLimpio);
                // Buscar en todas las carpetas que contengan el código
                $basePaths = ['uploads', 'uploads/document'];
                foreach ($basePaths as $basePath) {
                    if (Storage::disk('local')->exists($basePath)) {
                        $archivos = Storage::disk('local')->allFiles($basePath);
                        foreach ($archivos as $archivo) {
                            $rutaCompleta = $archivo;
                            // Si la ruta contiene el código
                            if (strpos($rutaCompleta, $codigoLimpio) !== false) {
                                $posiblesRutas[] = $archivo;
                            }
                        }
                    }
                }
            }
            
            // Probar todas las rutas posibles
            foreach ($posiblesRutas as $ruta) {
                if (Storage::disk('local')->exists($ruta)) {
                    $filePath = $ruta;
                    break;
                }
            }
            
            // Ruta del QR (SOLO si existe en la tabla antigua - NO generar nuevos automáticamente)
            $qrPath = null;
            if (isset($doc->qr_path) && !empty($doc->qr_path)) {
                $qrRutaAntigua = $doc->qr_path;
                $qrFilename = basename($qrRutaAntigua);
                
                // Verificar si existe en storage/qrcodes
                if (Storage::disk('qrcodes')->exists($qrFilename)) {
                    $qrPath = 'qrcodes/' . $qrFilename;
                } else {
                    // Intentar copiar desde ruta antigua si existe
                    if (Storage::disk('local')->exists($qrRutaAntigua)) {
                        $contenido = Storage::disk('local')->get($qrRutaAntigua);
                        Storage::disk('qrcodes')->put($qrFilename, $contenido);
                        $qrPath = 'qrcodes/' . $qrFilename;
                    }
                    // Si no existe, dejar qrPath como null (NO generar automáticamente)
                }
            }
            // Si no hay qr_path en la tabla antigua, dejar como null
            // Los QRs se generarán manualmente después si es necesario
            
            // Ruta del PDF final (si existe)
            if (isset($doc->final_path) && !empty($doc->final_path)) {
                $finalRutaAntigua = $doc->final_path;
                $finalFilename = basename($finalRutaAntigua);
                
                // Verificar si existe en storage/final
                $folderPart = dirname($finalRutaAntigua);
                if (Storage::disk('final')->exists($finalFilename)) {
                    $finalPath = 'final/' . $finalFilename;
                } else {
                    // Intentar copiar desde ruta antigua si existe
                    if (Storage::disk('local')->exists($finalRutaAntigua)) {
                        $contenido = Storage::disk('local')->get($finalRutaAntigua);
                        Storage::disk('final')->put($finalFilename, $contenido);
                        $finalPath = 'final/' . $finalFilename;
                    }
                }
            }
            
            // Tamaño del archivo
            // La tabla antigua puede tener file_size como string (ej: "723.59 KB") o como int
            $fileSize = 0;
            if ($filePath && Storage::disk('local')->exists($filePath)) {
                $fileSize = Storage::disk('local')->size($filePath);
            } elseif (isset($doc->file_size) && !empty($doc->file_size)) {
                // Si es un número, usarlo directamente
                if (is_numeric($doc->file_size)) {
                    $fileSize = (int)$doc->file_size;
                } else {
                    // Intentar convertir string a bytes (ej: "723.59 KB")
                    $sizeStr = trim($doc->file_size);
                    if (preg_match('/([\d.]+)\s*(KB|MB|GB)/i', $sizeStr, $matches)) {
                        $value = (float)$matches[1];
                        $unit = strtoupper($matches[2]);
                        switch ($unit) {
                            case 'KB':
                                $fileSize = (int)($value * 1024);
                                break;
                            case 'MB':
                                $fileSize = (int)($value * 1024 * 1024);
                                break;
                            case 'GB':
                                $fileSize = (int)($value * 1024 * 1024 * 1024);
                                break;
                            default:
                                $fileSize = (int)$value;
                        }
                    } else {
                        $fileSize = (int)$sizeStr;
                    }
                }
            }
            
            // Posición del QR (si existe en formato JSON)
            $qrPosition = null;
            if (isset($doc->qr_position) && !empty($doc->qr_position)) {
                $qrPosition = is_string($doc->qr_position) 
                    ? json_decode($doc->qr_position, true) 
                    : $doc->qr_position;
            }
            
            // Estado
            $status = 'completed';
            if (isset($doc->qr_status)) {
                $status = $doc->qr_status;
            } elseif (isset($doc->status)) {
                $status = $doc->status;
            }
            
            // Contador de escaneos (la tabla antigua no tiene estos campos)
            $scanCount = isset($doc->scan_count) ? (int)$doc->scan_count : 0;
            $lastScannedAt = isset($doc->last_scanned_at) ? $doc->last_scanned_at : null;
            
            // Fechas (la tabla antigua usa creation_date y update_date, o created_at y updated_at)
            $createdAt = isset($doc->creation_date) && !empty($doc->creation_date) 
                ? $doc->creation_date 
                : (isset($doc->created_at) && !empty($doc->created_at) ? $doc->created_at : now());
            $updatedAt = isset($doc->update_date) && !empty($doc->update_date)
                ? $doc->update_date
                : (isset($doc->updated_at) && !empty($doc->updated_at) ? $doc->updated_at : $createdAt);
            
            // Si aún no se encontró, usar una ruta por defecto (pero marcar como no encontrado)
            if (!$filePath) {
                // Construir ruta basada en la estructura esperada
                // Aunque el archivo no exista, guardamos la ruta esperada para referencia
                if ($yearMonth && $prefixCode && $code) {
                    $codigoLimpio = preg_replace('/[^a-zA-Z0-9\-_\.]/', '', $code);
                    $codigoLimpio = trim($codigoLimpio);
                    $filePath = "uploads/document/{$prefixCode}/{$yearMonth}/{$codigoLimpio}/" . basename($originalFilename);
                } else {
                    $filePath = 'uploads/migrado_' . $docId . '.pdf';
                }
                echo "   ⚠️  Archivo no encontrado, usando ruta esperada: {$filePath}\n";
            } else {
                echo "   ✅ Archivo encontrado: {$filePath}\n";
            }
            
            // Crear registro en qr_files
            $qrFile = QrFile::create([
                'qr_id' => $qrId,
                'document_id' => $docId, // Relación con tabla antigua
                'folder_name' => $folderName,
                'original_filename' => $originalFilename,
                'file_path' => $filePath,
                'qr_path' => $qrPath,
                'final_path' => $finalPath,
                'file_size' => $fileSize,
                'qr_position' => $qrPosition,
                'status' => $status,
                'scan_count' => $scanCount,
                'last_scanned_at' => $lastScannedAt,
                'created_at' => $createdAt,
                'updated_at' => $updatedAt,
            ]);
            
            echo "      - Archivo: " . ($filePath ?? 'NO ENCONTRADO') . "\n";
            echo "      - QR: " . ($qrPath ?? 'NO EXISTE (se generará después si es necesario)') . "\n";
            echo "      - Estado: {$status}\n\n";
            
            $migrados++;
            
        } catch (\Exception $e) {
            echo "   ❌ ERROR: " . $e->getMessage() . "\n";
            echo "      Trace: " . $e->getTraceAsString() . "\n\n";
            $errores++;
        }
    }
    
    echo "\n=== RESUMEN DE MIGRACIÓN ===\n";
    echo "✅ Documentos migrados: {$migrados}\n";
    echo "❌ Errores: {$errores}\n";
    echo "📋 Total procesados: " . $documentosAntiguos->count() . "\n\n";
    
    if ($errores > 0) {
        echo "⚠️  IMPORTANTE: Revisa los errores y corrige los problemas antes de continuar.\n\n";
    } else {
        echo "✅ Migración completada exitosamente.\n\n";
    }
}

// Si se ejecuta directamente desde línea de comandos
if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($_SERVER['PHP_SELF'])) {
    require __DIR__ . '/../../../vendor/autoload.php';
    $app = require_once __DIR__ . '/../../../bootstrap/app.php';
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    
    migrarDocumentosAntiguos();
}

