<?php

namespace App\Helpers;

use Illuminate\Support\Facades\Cache;

/**
 * Helper para gestión eficiente de caché
 */
class CacheHelper
{
    /**
     * Invalidar caché relacionado con documentos
     */
    public static function invalidateDocumentsCache(): void
    {
        // Invalidar estadísticas
        Cache::forget('documents_stats_v2');
        
        // Invalidar todos los cachés de listados
        // En producción con Redis, usar tags sería mejor, pero por ahora invalidamos manualmente
        // Nota: Esto puede ser costoso si hay muchos cachés, pero asegura consistencia
        try {
            // Intentar limpiar caché de listados usando patrón
            // Si usas Redis, podrías usar: Cache::tags(['documents'])->flush();
            // Por ahora, invalidamos el caché completo para asegurar que no aparezcan documentos eliminados
            Cache::flush(); // Esto limpia todo el caché - en producción, usar tags sería mejor
        } catch (\Exception $e) {
            // Si falla, al menos invalidar las claves conocidas
            Cache::forget('documents_stats_v2');
        }
    }
    
    /**
     * Invalidar caché de un documento específico
     */
    public static function invalidateDocumentCache(string $qrId): void
    {
        Cache::forget('document_qr_' . $qrId);
        self::invalidateDocumentsCache();
    }
    
    /**
     * Invalidar caché de listados
     */
    public static function invalidateListCache(): void
    {
        // En producción, usar tags para invalidar todos los listados
        // Por ahora, el caché expira automáticamente después de 5 minutos
    }
}

