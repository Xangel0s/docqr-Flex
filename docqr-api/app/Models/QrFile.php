<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Modelo QrFile - Para nuevos documentos con sistema QR completo
 * 
 * Este modelo representa los documentos nuevos que usan el sistema completo de QR.
 * Puede relacionarse con la tabla 'document' existente o funcionar independientemente.
 */
class QrFile extends Model
{
    use SoftDeletes;

    /**
     * Nombre de la tabla en la base de datos
     */
    protected $table = 'qr_files';

    /**
     * Atributos que pueden ser asignados masivamente
     */
    protected $fillable = [
        'qr_id',
        'document_id', // Relación opcional con tabla document existente
        'folder_name',
        'original_filename',
        'file_path',
        'qr_path',
        'final_path',
        'archive_path', // Ruta del ZIP donde está comprimido
        'file_size',
        'qr_position',
        'status',
        'archived', // Si está archivado en ZIP
        'scan_count',
        'last_scanned_at',
        'original_file_deleted_at', // Fecha en que se eliminó el archivo original
    ];

    /**
     * Atributos que deben ser convertidos a tipos nativos
     */
    protected $casts = [
        'qr_position' => 'array',
        'file_size' => 'integer',
        'scan_count' => 'integer',
        'last_scanned_at' => 'datetime',
        'original_file_deleted_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Relación con Document (si existe relación)
     */
    public function document()
    {
        return $this->belongsTo(Document::class, 'document_id', 'document_id');
    }

    /**
     * Generar URL de visualización del QR
     */
    public function getViewUrlAttribute(): string
    {
        // Usar helper que respeta el protocolo de la solicitud actual (HTTPS si viene de ngrok)
        return \App\Helpers\UrlHelper::url("/api/view/{$this->qr_id}", request());
    }

    /**
     * Incrementar contador de escaneos
     */
    public function incrementScanCount(): void
    {
        $this->increment('scan_count');
        $this->update(['last_scanned_at' => now()]);
    }

    /**
     * Scope para archivos completados
     */
    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    /**
     * Scope para buscar por carpeta
     */
    public function scopeInFolder($query, string $folderName)
    {
        return $query->where('folder_name', $folderName);
    }
    
    /**
     * Extraer el tipo de documento del folder_name
     * 
     * Ejemplo: "CE-12345" -> "CE", "IN-ABC" -> "IN", "SU-XYZ" -> "SU"
     * 
     * @param string $folderName Nombre de la carpeta (formato: TIPO-CODIGO)
     * @return string Tipo de documento (CE, IN, SU) o "OTROS" si no coincide
     */
    public static function extractDocumentType(string $folderName): string
    {
        // Extraer las primeras letras antes del guion
        $parts = explode('-', $folderName);
        $type = strtoupper(trim($parts[0] ?? ''));
        
        // Validar que sea uno de los tipos permitidos
        $allowedTypes = ['CE', 'IN', 'SU'];
        
        if (in_array($type, $allowedTypes)) {
            return $type;
        }
        
        // Si no coincide, usar "OTROS" como carpeta por defecto
        return 'OTROS';
    }
}

