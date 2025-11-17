<?php

/**
 * Script para corregir file_path de documentos que no se encontraron durante la migración
 * Busca los archivos físicos y actualiza la BD
 */

$rootDir = realpath(__DIR__ . '/../..');
$autoloadPath = $rootDir . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
require $autoloadPath;

$bootstrapPath = $rootDir . DIRECTORY_SEPARATOR . 'bootstrap' . DIRECTORY_SEPARATOR . 'app.php';
$app = require_once $bootstrapPath;
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use App\Models\QrFile;

echo "\n=== CORREGIR FILE_PATH DE DOCUMENTOS ===\n\n";
echo "📌 Estrategia:\n";
echo "   - Documentos RECIENTES (últimos 6 meses): Corrección al 100%\n";
echo "   - Documentos ANTIGUOS: Mejor esfuerzo, lo más similar posible a originales\n\n";

// Buscar documentos con file_path que no existe físicamente
$documentos = QrFile::where(function($query) {
        $query->where('file_path', 'like', 'uploads/migrado_%')
              ->orWhere(function($q) {
                  $q->whereNotNull('file_path')
                    ->where('file_path', 'not like', 'uploads/%/%/%'); // Rutas que no siguen el patrón esperado
              });
    })
    ->get()
    ->filter(function($doc) {
        // Filtrar solo los que realmente no existen
        if (!$doc->file_path) return true;
        return !Storage::disk('local')->exists($doc->file_path);
    })
    ->sortByDesc(function($doc) {
        // Priorizar documentos recientes
        $fecha = $doc->created_at ?? '2000-01-01';
        return strtotime($fecha);
    });

echo "📋 Encontrados " . $documentos->count() . " documentos con file_path incorrecto\n\n";

$corregidos = 0;
$noEncontrados = 0;

foreach ($documentos as $doc) {
    // Extraer información del documento
    $prefixCode = null;
    $code = null;
    
    // Intentar extraer del folder_name
    if ($doc->folder_name) {
        $parts = explode('-', $doc->folder_name, 2);
        if (count($parts) >= 2) {
            $prefixCode = $parts[0];
            $code = $parts[1];
        }
    }
    
    // Obtener datos de tabla antigua ANTES de mostrar mensaje
    $docAntiguo = null;
    if (!$code && $doc->document_id) {
        $docAntiguo = DB::table('document')->where('document_id', $doc->document_id)->first();
        if ($docAntiguo) {
            $prefixCode = $docAntiguo->prefix_code ?? null;
            $code = $docAntiguo->code ?? null;
        }
    }
    
    // Determinar prioridad
    $fechaDoc = $docAntiguo->creation_date ?? $doc->created_at ?? '2000-01-01';
    $esReciente = strtotime($fechaDoc) >= strtotime('-6 months');
    $prioridad = $esReciente ? '🟢 RECIENTE' : '🟡 ANTIGUO';
    
    echo "{$prioridad} Buscando: {$doc->original_filename} (qr_id: {$doc->qr_id})\n";
    if ($docAntiguo) {
        echo "   Fecha original: {$fechaDoc}\n";
    }
    
    // Buscar el archivo con búsqueda exhaustiva
    $archivoEncontrado = null;
    
    if ($prefixCode) {
        $tipoPath = "uploads/{$prefixCode}";
        if (Storage::disk('local')->exists($tipoPath)) {
            $archivos = Storage::disk('local')->allFiles($tipoPath);
            
            // MÉTODO 1: Buscar por password_file (más confiable)
            if ($docAntiguo && isset($docAntiguo->password_file) && !empty($docAntiguo->password_file)) {
                foreach ($archivos as $archivo) {
                    $nombreArchivo = basename($archivo);
                    if (strpos($nombreArchivo, $docAntiguo->password_file) !== false) {
                        $archivoEncontrado = $archivo;
                        echo "   ✅ Encontrado por password_file\n";
                        break;
                    }
                }
            }
            
            // MÉTODO 2: Buscar por nombre de archivo exacto o similar
            if (!$archivoEncontrado) {
                $nombreArchivoBuscado = basename($doc->original_filename);
                $nombreArchivoSinExtension = pathinfo($nombreArchivoBuscado, PATHINFO_FILENAME);
                
                foreach ($archivos as $archivo) {
                    $nombreArchivoBase = basename($archivo);
                    $nombreArchivoBaseSinExt = pathinfo($nombreArchivoBase, PATHINFO_FILENAME);
                    
                    // Coincidencia exacta
                    if ($nombreArchivoBase === $nombreArchivoBuscado) {
                        $archivoEncontrado = $archivo;
                        echo "   ✅ Encontrado por nombre exacto\n";
                        break;
                    }
                    
                    // Coincidencia parcial (sin extensión)
                    if (strpos($nombreArchivoBaseSinExt, $nombreArchivoSinExtension) !== false ||
                        strpos($nombreArchivoSinExtension, $nombreArchivoBaseSinExt) !== false) {
                        $archivoEncontrado = $archivo;
                        echo "   ✅ Encontrado por nombre similar\n";
                        break;
                    }
                }
            }
            
            // MÉTODO 3: Buscar por código en la ruta (múltiples variaciones)
            if (!$archivoEncontrado && $code) {
                $codigoLimpio = trim($code);
                $codigoSinEspacios = preg_replace('/\s+/', '', $codigoLimpio);
                $codigoSinEspeciales = preg_replace('/[^a-zA-Z0-9\-_\.]/', '', $codigoLimpio);
                $codigoSinGuiones = str_replace('-', '', $codigoSinEspeciales);
                
                $codigosBuscar = array_unique([
                    $codigoLimpio,
                    $codigoSinEspacios,
                    $codigoSinEspeciales,
                    $codigoSinGuiones
                ]);
                
                foreach ($codigosBuscar as $codigoVariante) {
                    if (empty($codigoVariante)) continue;
                    foreach ($archivos as $archivo) {
                        // Buscar en toda la ruta, no solo en el nombre
                        if (strpos($archivo, $codigoVariante) !== false) {
                            $archivoEncontrado = $archivo;
                            echo "   ✅ Encontrado por código en ruta: {$codigoVariante}\n";
                            break 2;
                        }
                    }
                }
            }
            
            // MÉTODO 4: Para documentos recientes, búsqueda más agresiva
            if (!$archivoEncontrado && $esReciente) {
                // Buscar por fecha de creación en la ruta (YYYYMM)
                $yearMonth = date('Ym', strtotime($fechaDoc));
                $archivosFecha = Storage::disk('local')->allFiles("uploads/{$prefixCode}/{$yearMonth}");
                
                foreach ($archivosFecha as $archivo) {
                    // Buscar cualquier coincidencia parcial con el código
                    if ($code) {
                        $codigoLimpio = preg_replace('/[^a-zA-Z0-9]/', '', trim($code));
                        $nombreArchivo = basename($archivo);
                        if (strpos($nombreArchivo, substr($codigoLimpio, 0, 5)) !== false) {
                            $archivoEncontrado = $archivo;
                            echo "   ✅ Encontrado por búsqueda agresiva (reciente)\n";
                            break;
                        }
                    }
                }
            }
        }
    }
    
    if ($archivoEncontrado && Storage::disk('local')->exists($archivoEncontrado)) {
        // Actualizar file_path
        $doc->file_path = $archivoEncontrado;
        $doc->save();
        echo "   ✅ Corregido: {$archivoEncontrado}\n";
        $corregidos++;
    } else {
        if ($esReciente) {
            echo "   ❌ NO ENCONTRADO (CRÍTICO - documento reciente)\n";
        } else {
            echo "   ⚠️  No encontrado (documento antiguo, se mantiene como está)\n";
        }
        $noEncontrados++;
    }
    echo "\n";
}

echo "=== RESUMEN ===\n";
echo "✅ Corregidos: {$corregidos}\n";
echo "❌ No encontrados: {$noEncontrados}\n\n";

