# ✅ Sistema de Validación y Almacenamiento

## Principios del Sistema

### 1. **Solo se Guardan Documentos con QR Generado** ✅

El sistema **SOLO guarda documentos** en la base de datos si:
- El PDF se subió exitosamente
- El código QR se generó exitosamente
- Si falla la generación del QR, el PDF se elimina y NO se guarda en la BD

**Flujo:**
```
1. Usuario sube PDF → Se guarda temporalmente
2. Sistema genera QR → Si falla, elimina PDF y retorna error
3. Si QR OK → Guarda en BD con status 'uploaded'
4. Usuario embebe QR → Cambia a status 'completed'
```

### 2. **Cada Documento tiene ID Independiente** ✅

Cada documento tiene **DOS identificadores únicos**:

1. **`id`** (Auto-incremental)
   - ID numérico único de la tabla
   - Ejemplo: 1, 2, 3, 4...
   - Se usa para operaciones internas

2. **`qr_id`** (String único de 32 caracteres)
   - Hash único generado aleatoriamente
   - Ejemplo: `a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6`
   - Se usa en las URLs públicas del QR
   - **Único e independiente** para cada documento

**Ventajas:**
- No se puede adivinar IDs secuenciales
- URLs públicas más seguras
- Cada documento es completamente independiente

### 3. **Validación de Escaneos** ✅

Los escaneos **SOLO se cuentan** cuando:
- Se accede al documento a través de la URL del QR: `/api/view/{qr_id}`
- El documento existe en la BD
- El QR fue generado correctamente

**NO se cuentan:**
- Accesos directos al PDF sin pasar por `/api/view/`
- Documentos sin QR generado
- Documentos eliminados

### 4. **Búsqueda Mejorada** ✅

El buscador soporta:
- **Código de carpeta** (folder_name): Ej: "CE-12345", "IN-ABC"
- **Nombre de archivo** (original_filename): Ej: "documento.pdf"

Búsqueda en ambos campos simultáneamente.

### 5. **Filtros por Tipo de Documento** ✅

Filtro disponible por:
- **CE** (Certificado)
- **IN** (Informe de Ensayo)
- **SU** (Suplemento)
- **Todos** (sin filtro)

## Estructura de IDs

```sql
qr_files
├── id (BIGINT, AUTO_INCREMENT, PRIMARY KEY)
│   └── Ejemplo: 1, 2, 3, 4...
│
└── qr_id (VARCHAR(32), UNIQUE, INDEXED)
    └── Ejemplo: a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6
```

## Flujo Completo

### Subida de Documento
1. ✅ PDF se sube → Guardado temporal
2. ✅ QR se genera → Si falla, PDF se elimina
3. ✅ Registro en BD → Solo si QR OK
4. ✅ Status: `uploaded` (pendiente de embebido)

### Embebido de QR
1. ✅ Usuario posiciona QR en PDF
2. ✅ Sistema embebe QR en PDF
3. ✅ Elimina PDF original (ahorro de espacio)
4. ✅ Status: `completed` (listo para usar)

### Escaneo de QR
1. ✅ Usuario escanea QR → Accede a `/api/view/{qr_id}`
2. ✅ Sistema busca documento por `qr_id`
3. ✅ Incrementa `scan_count`
4. ✅ Actualiza `last_scanned_at`
5. ✅ Retorna PDF final con QR

## Validaciones Implementadas

- ✅ Solo se guardan documentos con QR generado
- ✅ Cada documento tiene ID único independiente
- ✅ Escaneos solo se cuentan cuando se accede por QR
- ✅ Búsqueda por código y nombre de archivo
- ✅ Filtros por tipo de documento

