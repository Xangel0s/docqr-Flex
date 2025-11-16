# ¿Qué es `file_path` y cómo funciona?

## Analogía Simple 🏠

Imagina que `file_path` es como una **dirección de una casa**:

```
file_path = "uploads/CE/CE-12345/documento.pdf"
```

Es como decir: "La casa está en la calle X, número Y"

## ¿Es como una cookie? ❌ NO

**NO es como una cookie.** Es simplemente un **texto** que guarda la **dirección/ruta** del archivo.

## ¿Cómo funciona?

### 1. Cuando subes un PDF:

```
📄 Archivo físico en el disco:
   C:\xampp\htdocs\docqrgeofal\docqr-api\storage\app\uploads\CE\CE-12345\documento.pdf

📝 file_path en la base de datos:
   "uploads/CE/CE-12345/documento.pdf"
```

### 2. Cuando generas el PDF final con QR:

```
📄 Archivo físico nuevo en el disco:
   C:\xampp\htdocs\docqrgeofal\docqr-api\storage\app\final\CE\documento-final.pdf

📝 final_path en la base de datos:
   "final/CE/documento-final.pdf"
```

### 3. Cuando eliminas el archivo original:

```
❌ Archivo físico ELIMINADO del disco:
   (Ya no existe físicamente)

✅ file_path SIGUE en la base de datos:
   "uploads/CE/CE-12345/documento.pdf"
   (Solo como referencia histórica)
```

## ¿Por qué mantener `file_path` en la BD?

**Porque:**
- ✅ Es solo un texto (no ocupa mucho espacio)
- ✅ Sirve como referencia histórica
- ✅ La columna NO permite NULL (restricción de BD)
- ✅ Ya no se usa para nada (solo se usa `final_path`)

## ¿Qué archivo se usa realmente?

Mira el código en `FileController.php`:

```php
// Determinar qué archivo servir
if ($qrFile->final_path) {
    // ✅ USA ESTE: PDF final con QR embebido
    $filePath = $qrFile->final_path;
} elseif ($qrFile->file_path) {
    // ⚠️ SOLO SI NO HAY FINAL: PDF original
    $filePath = $qrFile->file_path;
}
```

**Conclusión:** Una vez que tienes `final_path`, el sistema **siempre usa ese**. El `file_path` original ya no se necesita.

## Resumen Visual

```
┌─────────────────────────────────────────┐
│  BASE DE DATOS (qr_files)              │
├─────────────────────────────────────────┤
│  file_path: "uploads/CE/..."           │ ← Solo texto (referencia)
│  final_path: "final/CE/..."           │ ← Este es el que se usa ✅
│  qr_path: "qrcodes/..."                │ ← Imagen QR
└─────────────────────────────────────────┘
           │
           │
           ▼
┌─────────────────────────────────────────┐
│  DISCO DURO (storage/app/)              │
├─────────────────────────────────────────┤
│  ❌ uploads/CE/... (ELIMINADO)          │ ← Ya no existe
│  ✅ final/CE/... (EXISTE)               │ ← Este se usa
│  ✅ qrcodes/... (EXISTE)                │ ← Este se usa
└─────────────────────────────────────────┘
```

## ¿Se guarda correcto? ✅ SÍ

**Sí, se guarda correcto porque:**

1. ✅ El PDF final (`final_path`) se guarda correctamente
2. ✅ El archivo físico existe en el disco
3. ✅ El sistema usa `final_path` para servir el PDF
4. ✅ `file_path` queda como referencia (aunque el archivo ya no exista)

**No hay problema** porque el sistema **nunca intenta usar** `file_path` si ya existe `final_path`.

