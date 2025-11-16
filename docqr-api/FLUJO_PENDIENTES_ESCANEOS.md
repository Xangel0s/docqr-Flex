# 📋 Flujo de Pendientes y Escaneos - DocQR

## 🔄 Cómo se Guardan los Documentos Pendientes

### Paso 1: Subida de PDF
```
Usuario sube PDF → UploadController::upload()
```

**Proceso:**
1. ✅ Validar PDF (1 página, formato correcto)
2. ✅ Generar `qr_id` único (32 caracteres aleatorios)
3. ✅ Guardar PDF en: `storage/app/uploads/{TIPO}/{CODIGO}/{YYYYMM}-{qr_id}-archivo.pdf`
4. ✅ Generar código QR (imagen PNG)
5. ✅ **SI el QR se genera exitosamente** → Guardar en BD con:
   ```php
   status = 'uploaded'  // ← ESTADO PENDIENTE
   scan_count = 0       // ← Inicia en 0
   qr_id = 'abc123...'  // ← ID único
   ```
6. ❌ **SI falla la generación del QR** → Eliminar PDF, NO guardar en BD

### Resultado:
- **Estado**: `uploaded` (Pendiente)
- **Significado**: PDF subido, QR generado, pero QR aún NO embebido en el PDF
- **Visible en**: Filtro "Pendientes"

---

## ✅ Cómo se Marcan como Completados

### Paso 2: Embebido de QR
```
Usuario posiciona QR → EmbedController::embed()
```

**Proceso:**
1. ✅ Buscar documento por `qr_id`
2. ✅ Validar posición del QR (dentro de límites del PDF)
3. ✅ Embebir QR en el PDF usando FPDI
4. ✅ Guardar PDF final en: `storage/app/final/{TIPO}/{YYYYMM}/archivo.pdf`
5. ✅ Eliminar PDF original (ahorro de espacio)
6. ✅ Actualizar en BD:
   ```php
   status = 'completed'  // ← CAMBIA A COMPLETADO
   final_path = 'final/CE/...'  // ← Ruta del PDF final
   qr_position = {x, y, width, height}  // ← Posición del QR
   ```

### Resultado:
- **Estado**: `completed` (Completado)
- **Significado**: PDF tiene QR embebido, listo para usar
- **Visible en**: Filtro "Completados"

---

## 📊 Cómo se Verifican y Guardan los Escaneos

### Paso 3: Escaneo del QR
```
Usuario escanea QR → ViewController::view($qr_id)
```

**Proceso:**
1. ✅ Buscar documento por `qr_id` en la BD
2. ✅ **Incrementar contador automáticamente**:
   ```php
   scan_count = scan_count + 1  // ← Se incrementa
   last_scanned_at = now()       // ← Se actualiza fecha
   ```
3. ✅ Servir el PDF final (o original si no tiene final)
4. ✅ Retornar PDF al usuario

### Validación:
- ✅ **Solo se cuenta** si se accede por `/api/view/{qr_id}`
- ✅ **Solo se cuenta** si el documento existe en BD
- ✅ **Solo se cuenta** si el QR fue generado correctamente
- ❌ **NO se cuenta** acceso directo al PDF sin pasar por `/api/view/`
- ❌ **NO se cuenta** si el documento no existe

### Resultado:
- **scan_count**: Se incrementa cada vez que se escanea
- **last_scanned_at**: Se actualiza con la fecha/hora del escaneo
- **Visible en**: Filtro "Con escaneos" o "Sin escaneos"

---

## 📈 Estados del Documento

### Estado: `uploaded` (Pendiente)
```php
status = 'uploaded'
final_path = null
scan_count = 0
```
- **Significado**: PDF subido, QR generado, pero NO embebido aún
- **Acciones**: Usuario debe ir al editor para embebir QR
- **Escaneos**: Aún no se pueden escanear (no hay QR visible en PDF)

### Estado: `completed` (Completado)
```php
status = 'completed'
final_path = 'final/CE/...'
scan_count = 0 o más
```
- **Significado**: PDF tiene QR embebido, listo para usar
- **Acciones**: Puede ser escaneado
- **Escaneos**: Se cuentan cuando alguien escanea el QR

---

## 🔍 Verificación de Escaneos

### Método: `incrementScanCount()`
```php
public function incrementScanCount(): void
{
    $this->increment('scan_count');        // scan_count++
    $this->update(['last_scanned_at' => now()]);  // Actualiza fecha
}
```

### Cuándo se Ejecuta:
- ✅ Automáticamente cuando alguien accede a `/api/view/{qr_id}`
- ✅ Se ejecuta ANTES de servir el PDF
- ✅ Se guarda en la BD inmediatamente

### Datos que se Guardan:
1. **scan_count**: Contador total de escaneos
2. **last_scanned_at**: Fecha y hora del último escaneo

---

## 📊 Ejemplo de Flujo Completo

```
1. Usuario sube PDF
   ↓
   status = 'uploaded'
   scan_count = 0
   last_scanned_at = null
   
2. Usuario embebe QR
   ↓
   status = 'completed'
   scan_count = 0 (aún no escaneado)
   last_scanned_at = null
   
3. Alguien escanea el QR (1ra vez)
   ↓
   status = 'completed'
   scan_count = 1
   last_scanned_at = '2025-11-16 10:30:00'
   
4. Alguien escanea el QR (2da vez)
   ↓
   status = 'completed'
   scan_count = 2
   last_scanned_at = '2025-11-16 14:45:00'
```

---

## ✅ Validaciones Implementadas

1. **Solo se guardan documentos con QR generado**
   - Si falla el QR → NO se guarda en BD

2. **Escaneos solo se cuentan cuando se accede por QR**
   - Ruta: `/api/view/{qr_id}`
   - Se incrementa automáticamente

3. **Cada documento tiene ID independiente**
   - `id`: Auto-incremental (1, 2, 3...)
   - `qr_id`: Único de 32 caracteres

4. **Estados claramente definidos**
   - `uploaded`: Pendiente de embebido
   - `completed`: Listo para usar

