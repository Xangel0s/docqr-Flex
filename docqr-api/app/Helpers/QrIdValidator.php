<?php

namespace App\Helpers;

use Illuminate\Support\Facades\Log;

/**
 * Helper para validar qr_id contra inyección SQL y otros ataques
 */
class QrIdValidator
{
    /**
     * Validar formato de qr_id
     * 
     * qr_id se genera con Str::random(12), así que debe ser:
     * - Alfanumérico (letras y números)
     * - Longitud entre 8 y 32 caracteres (permite flexibilidad)
     * - Sin caracteres especiales peligrosos
     * 
     * @param string $qrId ID del QR a validar
     * @return bool True si es válido, False si no
     */
    public static function isValid(string $qrId): bool
    {
        // Validar que no esté vacío
        if (empty($qrId)) {
            return false;
        }
        
        // Validar longitud (8-32 caracteres para flexibilidad)
        $length = strlen($qrId);
        if ($length < 8 || $length > 32) {
            Log::warning('qr_id con longitud inválida:', [
                'qr_id' => $qrId,
                'length' => $length
            ]);
            return false;
        }
        
        // Validar que solo contenga caracteres alfanuméricos (letras y números)
        // Permitir también guiones y guiones bajos para compatibilidad
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $qrId)) {
            Log::warning('qr_id con caracteres inválidos (posible inyección SQL):', [
                'qr_id' => $qrId,
                'sanitized' => preg_replace('/[^a-zA-Z0-9_-]/', '', $qrId)
            ]);
            return false;
        }
        
        return true;
    }
    
    /**
     * Validar y sanitizar qr_id
     * 
     * @param string $qrId ID del QR a validar
     * @return string|null qr_id sanitizado o null si es inválido
     */
    public static function sanitize(string $qrId): ?string
    {
        if (!self::isValid($qrId)) {
            return null;
        }
        
        // Sanitizar removiendo caracteres peligrosos (aunque ya validamos)
        $sanitized = preg_replace('/[^a-zA-Z0-9_-]/', '', $qrId);
        
        // Verificar que la sanitización no cambió el valor
        if ($sanitized !== $qrId) {
            Log::warning('qr_id fue sanitizado (caracteres removidos):', [
                'original' => $qrId,
                'sanitized' => $sanitized
            ]);
        }
        
        return $sanitized;
    }
    
    /**
     * Validar qr_id y lanzar excepción si es inválido
     * 
     * @param string $qrId ID del QR a validar
     * @throws \InvalidArgumentException Si el qr_id es inválido
     * @return string qr_id validado
     */
    public static function validateOrFail(string $qrId): string
    {
        if (!self::isValid($qrId)) {
            Log::error('qr_id inválido detectado (posible ataque):', [
                'qr_id' => $qrId,
                'length' => strlen($qrId),
                'contains_sql' => preg_match('/(union|select|insert|update|delete|drop|exec|script|javascript|onerror|onload)/i', $qrId)
            ]);
            throw new \InvalidArgumentException('ID de QR inválido');
        }
        
        return $qrId;
    }
}

