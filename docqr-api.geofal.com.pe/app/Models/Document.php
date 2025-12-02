<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Modelo Document - Adaptado para trabajar con la base de datos existente
 * 
 * Este modelo representa los documentos de la tabla 'document' existente
 * y se adapta para trabajar con el nuevo sistema de códigos QR.
 */
class Document extends Model
{
    use SoftDeletes;

    /**
     * Nombre de la tabla en la base de datos
     */
    protected $table = 'document';

    /**
     * Clave primaria
     */
    protected $primaryKey = 'document_id';

    /**
     * Indica si los IDs son auto-incrementales
     */
    public $incrementing = true;

    /**
     * Tipo de datos de la clave primaria
     */
    protected $keyType = 'int';

    /**
     * Atributos que pueden ser asignados masivamente
     */
    protected $fillable = [
        'document_type_id',
        'prefix_code',
        'code',
        'folder_name', // Nueva columna para el sistema QR
        'file_name',
        'file_size',
        'documenting_user_id',
        'status_id',
        'is_active',
        'audit_user_id',
        'password_file', // Este campo puede usarse como qr_id
        'qr_path', // Nueva columna
        'final_path', // Nueva columna
        'qr_position', // Nueva columna (JSON)
        'qr_status', // Nueva columna
        'scan_count', // Nueva columna
        'last_scanned_at', // Nueva columna
        'is_file_name_encript',
    ];

    /**
     * Atributos que deben ser convertidos a tipos nativos
     */
    protected $casts = [
        'is_active' => 'boolean',
        'creation_date' => 'datetime',
        'update_date' => 'datetime',
        'last_scanned_at' => 'datetime',
        'qr_position' => 'array',
        'scan_count' => 'integer',
        'is_file_name_encript' => 'integer',
    ];

    /**
     * Atributos que deben ser ocultos en arrays/JSON
     */
    protected $hidden = [
        'password_file',
        'is_file_name_encript',
    ];

    /**
     * Nombre de las columnas de timestamps
     */
    const CREATED_AT = 'creation_date';
    const UPDATED_AT = 'update_date';

    /**
     * Relación con QrFile (si existe la tabla qr_files)
     */
    public function qrFile()
    {
        return $this->hasOne(QrFile::class, 'document_id', 'document_id');
    }

    /**
     * Obtener el QR ID (usa password_file como fallback)
     */
    public function getQrIdAttribute(): string
    {
        if ($this->qrFile) {
            return $this->qrFile->qr_id;
        }
        
        return $this->password_file ?? '';
    }

    /**
     * Scope para documentos activos
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope para documentos con QR completado
     */
    public function scopeQrCompleted($query)
    {
        return $query->where('qr_status', 'completed');
    }
}

