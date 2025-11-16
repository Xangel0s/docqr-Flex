# Alternativas Modernas para Gestión de Archivos

## Problema Actual

El campo `file_path` no puede ser NULL en la base de datos, pero queremos eliminar el archivo original para ahorrar espacio después de generar el PDF final con QR.

## Solución Implementada ✅

**Campo `original_file_deleted_at`**: Marca cuándo se eliminó el archivo original, manteniendo `file_path` como referencia histórica.

### Ventajas:
- ✅ No viola restricciones de BD
- ✅ Mantiene trazabilidad histórica
- ✅ Permite auditoría de eliminaciones
- ✅ Fácil de implementar

## Alternativas Modernas Recomendadas

### 1. **Jobs/Queues para Procesamiento Asíncrono** ⭐ RECOMENDADO

**Ventajas:**
- No bloquea la respuesta HTTP
- Permite reintentos automáticos
- Mejor experiencia de usuario
- Escalable horizontalmente

**Implementación:**
```php
// app/Jobs/DeleteOriginalFileJob.php
class DeleteOriginalFileJob implements ShouldQueue
{
    public function __construct(
        public QrFile $qrFile
    ) {}

    public function handle(): void
    {
        if ($this->qrFile->file_path && Storage::disk('local')->exists($this->qrFile->file_path)) {
            Storage::disk('local')->delete($this->qrFile->file_path);
            $this->qrFile->update(['original_file_deleted_at' => now()]);
        }
    }
}

// En EmbedController:
DeleteOriginalFileJob::dispatch($qrFile)->delay(now()->addMinutes(5));
```

### 2. **Eventos y Listeners** ⭐ RECOMENDADO

**Ventajas:**
- Desacopla la lógica
- Fácil de testear
- Permite múltiples acciones al completar

**Implementación:**
```php
// app/Events/QrEmbedded.php
class QrEmbedded
{
    public function __construct(public QrFile $qrFile) {}
}

// app/Listeners/DeleteOriginalFile.php
class DeleteOriginalFile
{
    public function handle(QrEmbedded $event): void
    {
        // Lógica de eliminación
    }
}
```

### 3. **Storage en la Nube (S3, Azure Blob, etc.)** ⭐ RECOMENDADO PARA PRODUCCIÓN

**Ventajas:**
- Escalable automáticamente
- Redundancia automática
- Lifecycle policies (eliminación automática)
- CDN integrado

**Implementación:**
```php
// config/filesystems.php
's3' => [
    'driver' => 's3',
    'key' => env('AWS_ACCESS_KEY_ID'),
    'secret' => env('AWS_SECRET_ACCESS_KEY'),
    'region' => env('AWS_DEFAULT_REGION'),
    'bucket' => env('AWS_BUCKET'),
],

// Lifecycle policy en S3 para eliminar archivos originales después de 30 días
```

### 4. **Scheduled Jobs para Limpieza Automática**

**Ventajas:**
- Eliminación automática programada
- No bloquea operaciones críticas
- Configurable por reglas de negocio

**Implementación:**
```php
// app/Console/Kernel.php
protected function schedule(Schedule $schedule)
{
    $schedule->command('files:cleanup-originals')
             ->daily()
             ->at('02:00');
}

// app/Console/Commands/CleanupOriginalFiles.php
class CleanupOriginalFiles extends Command
{
    public function handle()
    {
        QrFile::whereNotNull('final_path')
              ->whereNull('original_file_deleted_at')
              ->where('created_at', '<', now()->subDays(7))
              ->chunk(100, function ($files) {
                  foreach ($files as $file) {
                      // Eliminar archivo y marcar
                  }
              });
    }
}
```

### 5. **Soft Delete para Archivos (No Eliminar Físicamente)**

**Ventajas:**
- Recuperación fácil
- Auditoría completa
- Cumplimiento legal

**Implementación:**
```php
// Mantener archivo pero marcarlo como "eliminado lógicamente"
// Usar campo original_file_deleted_at (ya implementado)
```

### 6. **Políticas de Retención Configurables**

**Ventajas:**
- Flexible según necesidades
- Ahorro de espacio gradual
- Control de costos

**Implementación:**
```php
// .env
ORIGINAL_FILE_RETENTION_DAYS=7

// En código:
$retentionDays = config('files.original_retention_days', 7);
if ($qrFile->created_at->addDays($retentionDays)->isPast()) {
    // Eliminar
}
```

### 7. **Versionado de Archivos**

**Ventajas:**
- Historial completo
- Rollback posible
- Auditoría detallada

**Implementación:**
```php
// Guardar versiones:
// - original_v1.pdf
// - original_v2.pdf (si se re-sube)
// - final_v1.pdf
```

## Recomendación Final

Para tu caso específico, recomiendo:

1. **Corto plazo**: ✅ Solución actual con `original_file_deleted_at`
2. **Mediano plazo**: Agregar **Jobs/Queues** para eliminación asíncrona
3. **Largo plazo**: Migrar a **Storage en la Nube** (S3, Azure Blob)

## Migración a Implementar

Ejecuta la migración para agregar el campo:

```bash
php artisan migrate
```

Esto agregará el campo `original_file_deleted_at` sin afectar datos existentes.

