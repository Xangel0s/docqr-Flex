# Compatibilidad con cPanel - Eliminación de Archivos

## ✅ SÍ, es válido y seguro eliminar después de guardar el PDF con QR

### Flujo Actual (Correcto):

```
1. Usuario sube PDF
   └─> Se guarda: file_path = "uploads/CE/CE-12345/documento.pdf"
   └─> Estado: "uploaded"

2. Usuario posiciona QR y guarda
   └─> Se genera: final_path = "final/CE/documento-final.pdf"
   └─> Se guarda en BD: ✅ final_path guardado
   └─> Estado: "completed"

3. ✅ AHORA SÍ se elimina el original
   └─> Se elimina archivo físico: uploads/CE/CE-12345/documento.pdf
   └─> file_path queda en BD (solo referencia)
   └─> El sistema usa: final_path (siempre disponible)
```

## ✅ cPanel SÍ lo soporta

### ¿Por qué es compatible?

1. **PHP tiene permisos de escritura/eliminación:**
   ```php
   Storage::disk('local')->delete($filePath);
   // Esto usa funciones nativas de PHP: unlink()
   // cPanel permite esto sin problemas
   ```

2. **Laravel Storage usa rutas relativas:**
   ```php
   'root' => storage_path('app'),
   // En cPanel: /home/usuario/public_html/storage/app/
   // Permisos: 755 (directorio) y 644 (archivos)
   ```

3. **No requiere configuración especial:**
   - No necesita SSH
   - No necesita permisos root
   - Funciona con permisos de usuario normal

### Verificación de Permisos en cPanel:

**Permisos necesarios:**
```
storage/app/uploads/          → 755 (directorio)
storage/app/uploads/CE/       → 755 (directorio)
storage/app/final/           → 755 (directorio)
storage/app/final/CE/        → 755 (directorio)
```

**El código verifica antes de eliminar:**
```php
if ($qrFile->file_path && Storage::disk('local')->exists($qrFile->file_path)) {
    Storage::disk('local')->delete($qrFile->file_path);
    // ✅ Solo elimina si existe
}
```

## ✅ Seguridad y Robustez

### 1. **Verificación de existencia:**
```php
// ✅ Verifica que existe antes de eliminar
Storage::disk('local')->exists($qrFile->file_path)
```

### 2. **Manejo de errores:**
```php
try {
    Storage::disk('local')->delete($qrFile->file_path);
} catch (\Exception $e) {
    // ✅ Si falla, no es crítico - el archivo final ya está guardado
    Log::warning('No se pudo eliminar PDF original');
}
```

### 3. **El sistema siempre usa final_path:**
```php
// En FileController.php
if ($qrFile->final_path) {
    // ✅ Siempre usa el PDF final (si existe)
    $filePath = $qrFile->final_path;
} elseif ($qrFile->file_path) {
    // ⚠️ Solo si no hay final (ya no pasa después de guardar)
    $filePath = $qrFile->file_path;
}
```

## 📋 Checklist para cPanel

### Antes de desplegar:

1. ✅ Verificar permisos de carpetas:
   ```bash
   chmod -R 755 storage/app/uploads
   chmod -R 755 storage/app/final
   chmod -R 755 storage/app/qrcodes
   ```

2. ✅ Verificar que PHP puede escribir:
   - cPanel → Select PHP Version → PHP 8.1+
   - Verificar que `file_uploads = On`

3. ✅ Verificar espacio en disco:
   - cPanel → Disk Usage
   - Los archivos originales se eliminan automáticamente

### Después de desplegar:

1. ✅ Probar subir un PDF
2. ✅ Probar posicionar QR y guardar
3. ✅ Verificar que el archivo original se elimina
4. ✅ Verificar que el PDF final se puede ver

## ⚠️ Consideraciones Especiales

### Si usas múltiples servidores (Load Balancer):

**Problema:** Cada servidor tiene su propio disco.

**Solución:** Usar Storage en la Nube (S3, Azure Blob):
```php
// config/filesystems.php
's3' => [
    'driver' => 's3',
    'key' => env('AWS_ACCESS_KEY_ID'),
    'secret' => env('AWS_SECRET_ACCESS_KEY'),
    'region' => env('AWS_DEFAULT_REGION'),
    'bucket' => env('AWS_BUCKET'),
],
```

### Si tienes problemas de permisos:

**Solución:** Usar File Manager de cPanel:
1. Ir a File Manager
2. Navegar a `storage/app/uploads/`
3. Click derecho → Change Permissions
4. Marcar: Read, Write, Execute (755)

## ✅ Conclusión

**SÍ, es válido y seguro:**

1. ✅ **Es válido:** El código actual ya lo hace correctamente
2. ✅ **cPanel lo soporta:** PHP puede eliminar archivos sin problemas
3. ✅ **No requiere configuración especial:** Funciona con permisos normales
4. ✅ **Es seguro:** Verifica existencia antes de eliminar
5. ✅ **Maneja errores:** Si falla, no es crítico

**El flujo actual es correcto y compatible con cPanel.**

