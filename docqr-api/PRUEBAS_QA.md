# 🧪 Guía de Pruebas Q/A - DocQR

## ✅ 1. Organización de Archivos por Tipo de Documento

### Estructura de Carpetas

**PDFs Originales (uploads) - NUEVA ESTRUCTURA OPTIMIZADA:**
```
storage/app/uploads/
├── CE/                    # Certificados
│   └── 202511/            # Año y mes (YYYYMM)
│       └── {qr_id}/      # Carpeta única por documento
│           └── documento.pdf  # Nombre original (sin prefijos)
├── IN/                    # Informes de Ensayo
│   └── 202511/
│       └── {qr_id}/
│           └── documento.pdf
├── SU/                    # Suplementos
│   └── 202511/
│       └── {qr_id}/
│           └── documento.pdf
└── OTROS/                 # Documentos sin tipo definido
    └── 202511/
        └── {qr_id}/
            └── documento.pdf
```

**PDFs Finales (con QR) - NUEVA ESTRUCTURA OPTIMIZADA:**
```
storage/app/final/
├── CE/                    # Certificados
│   └── 202511/            # Año y mes (YYYYMM)
│       └── {qr_id}/      # Carpeta única por documento
│           └── documento.pdf  # Nombre original (sin prefijos)
├── IN/                    # Informes de Ensayo
│   └── 202511/
│       └── {qr_id}/
│           └── documento.pdf
└── SU/                    # Suplementos
    └── 202511/
        └── {qr_id}/
            └── documento.pdf
```

**Códigos QR:**
```
storage/app/qrcodes/
└── {qr_id}.png            # Sin organización por tipo
```

### Formato de Nombres de Archivo (NUEVA ESTRUCTURA)

**PDF Original:**
- **Ubicación:** `uploads/{TIPO}/{YYYYMM}/{qr_id}/{nombre_original}.pdf`
- **Ejemplo:** `uploads/CE/202511/abc123xyz/documento.pdf`
- **Ventajas:**
  - ✅ Organización por fecha (fácil limpieza por mes)
  - ✅ Cada documento en su propia carpeta (qr_id único)
  - ✅ Nombre de archivo limpio (sin prefijos)
  - ✅ Más escalable para miles de documentos

**PDF Final:**
- **Ubicación:** `final/{TIPO}/{YYYYMM}/{qr_id}/{nombre_original}.pdf`
- **Ejemplo:** `final/CE/202511/abc123xyz/documento.pdf`
- **Misma estructura que el original** para consistencia

### Pruebas a Realizar

#### ✅ Test 1: Subir PDF tipo CE
1. Seleccionar tipo: **CE**
2. Ingresar código: **CE-12345**
3. Subir PDF: `documento.pdf`
4. **Verificar:**
   - ✅ Archivo se guarda en: `storage/app/uploads/CE/202511/{qr_id}/`
   - ✅ Nombre: `documento.pdf` (nombre original, sin prefijos)
   - ✅ QR se genera en: `storage/app/qrcodes/{qr_id}.png`

#### ✅ Test 2: Subir PDF tipo IN
1. Seleccionar tipo: **IN**
2. Ingresar código: **IN-ABC**
3. Subir PDF: `informe.pdf`
4. **Verificar:**
   - ✅ Archivo se guarda en: `storage/app/uploads/IN/202511/{qr_id}/`
   - ✅ Nombre: `informe.pdf` (nombre original)
   - ✅ Carpeta por mes actual (202511)

#### ✅ Test 3: Subir PDF tipo SU
1. Seleccionar tipo: **SU**
2. Ingresar código: **SU-XYZ**
3. Subir PDF: `suplemento.pdf`
4. **Verificar:**
   - ✅ Archivo se guarda en: `storage/app/uploads/SU/202511/{qr_id}/`
   - ✅ Nombre: `suplemento.pdf` (nombre original)

#### ✅ Test 4: Guardar PDF Final (con QR)
1. Después de subir, abrir editor
2. Posicionar QR y guardar
3. **Verificar:**
   - ✅ PDF final se guarda en: `storage/app/final/CE/202511/{qr_id}/` (misma estructura que original)
   - ✅ Nombre: `documento.pdf` (nombre original, sin prefijos)
   - ✅ PDF original se CONSERVA en `uploads/CE/202511/{qr_id}/`

#### ✅ Test 5: Tipo no reconocido
1. Ingresar código: **XX-123** (no es CE, IN, SU)
2. Subir PDF
3. **Verificar:**
   - ✅ Archivo se guarda en: `storage/app/uploads/OTROS/XX-123/`

---

## ✅ 2. Paginación de Lista de Documentos

### Configuración Actual

**Por Defecto:**
- **15 documentos por página**
- Paginación completa con:
  - `current_page`: Página actual
  - `last_page`: Última página
  - `per_page`: Documentos por página (15)
  - `total`: Total de documentos

**Configurable:**
- Se puede cambiar con parámetro `per_page` en la petición
- Ejemplo: `?per_page=20` para mostrar 20 por página

### Límites

**Sin límite máximo configurado:**
- El sistema puede manejar miles de documentos
- La paginación se calcula automáticamente
- Recomendado: 15-50 documentos por página para mejor rendimiento

### Pruebas a Realizar

#### ✅ Test 1: Lista con menos de 15 documentos
1. Tener menos de 15 documentos en total
2. Abrir lista de documentos
3. **Verificar:**
   - ✅ Muestra todos los documentos
   - ✅ No aparece paginación (o muestra "1 / 1")
   - ✅ Total correcto

#### ✅ Test 2: Lista con más de 15 documentos
1. Tener más de 15 documentos (ej: 25)
2. Abrir lista de documentos
3. **Verificar:**
   - ✅ Muestra solo 15 documentos en la primera página
   - ✅ Aparece paginación: "1 / 2" (o similar)
   - ✅ Botones "Siguiente" y "Anterior" funcionan
   - ✅ Segunda página muestra los 10 restantes

#### ✅ Test 3: Navegación entre páginas
1. Con más de 15 documentos
2. Ir a página 2
3. **Verificar:**
   - ✅ Carga correctamente los documentos de la página 2
   - ✅ Indicador de página se actualiza
   - ✅ Botón "Anterior" funciona
   - ✅ Botón "Siguiente" funciona (si hay más páginas)

#### ✅ Test 4: Filtros con paginación
1. Aplicar filtro (ej: solo tipo CE)
2. Si hay más de 15 resultados
3. **Verificar:**
   - ✅ Paginación se recalcula según resultados filtrados
   - ✅ Total muestra solo documentos filtrados
   - ✅ Navegación funciona correctamente

#### ✅ Test 5: Búsqueda con paginación
1. Realizar búsqueda que devuelva más de 15 resultados
2. **Verificar:**
   - ✅ Paginación funciona con resultados de búsqueda
   - ✅ Total muestra solo resultados encontrados

---

## 📋 Checklist Completo de Pruebas

### Organización de Archivos
- [ ] PDF tipo CE se guarda en `uploads/CE/{folder_name}/`
- [ ] PDF tipo IN se guarda en `uploads/IN/{folder_name}/`
- [ ] PDF tipo SU se guarda en `uploads/SU/{folder_name}/`
- [ ] PDF tipo desconocido se guarda en `uploads/OTROS/{folder_name}/`
- [ ] Nombre de archivo incluye mes/año: `{YYYYMM}-{qr_id}-{nombre}.pdf`
- [ ] PDF final se guarda en `final/{TIPO}/`
- [ ] PDF final conserva mes/año en el nombre
- [ ] QR se guarda en `qrcodes/{qr_id}.png`

### Paginación
- [ ] Lista muestra 15 documentos por página (por defecto)
- [ ] Paginación aparece cuando hay más de 15 documentos
- [ ] Navegación entre páginas funciona
- [ ] Total de documentos es correcto
- [ ] Filtros respetan paginación
- [ ] Búsqueda respeta paginación

### Funcionalidad General
- [ ] Subir PDF funciona
- [ ] Generar QR funciona
- [ ] Editor carga PDF original (sin QR)
- [ ] Guardar posiciona QR correctamente
- [ ] Descargar PDF final funciona
- [ ] Ver PDF en modal funciona
- [ ] Reposicionar QR funciona (múltiples veces)

---

## 🐛 Problemas Conocidos a Verificar

### 1. Permisos de Carpetas
- **Problema:** Si no hay permisos, no se pueden crear carpetas
- **Solución:** Verificar permisos 755/775 en `storage/app/`

### 2. Mes/Año en Nombre
- **Problema:** Si cambia de mes, el formato puede variar
- **Verificar:** El formato `YYYYMM` se mantiene consistente

### 3. Paginación con Filtros
- **Problema:** Al cambiar filtros, puede quedar en página inexistente
- **Verificar:** El sistema debe resetear a página 1 al cambiar filtros

---

## 📊 Métricas de Rendimiento

### Archivos
- **Tamaño máximo PDF:** 50MB (configurable)
- **Tiempo de procesamiento:** < 5 segundos (PDFs normales)
- **Espacio por documento:** ~2-5MB (depende del PDF)

### Paginación
- **Tiempo de carga:** < 1 segundo (15 documentos)
- **Escalabilidad:** Maneja miles de documentos sin problemas
- **Recomendación:** 15-50 documentos por página

---

## ✅ Criterios de Aceptación

### Organización de Archivos
✅ **PASO:** Todos los archivos se organizan correctamente por tipo
✅ **PASO:** Los nombres incluyen mes/año para facilitar búsqueda
✅ **PASO:** Los PDFs finales se organizan por tipo (sin subcarpetas por mes)

### Paginación
✅ **PASO:** Lista muestra 15 documentos por página
✅ **PASO:** Paginación funciona correctamente
✅ **PASO:** Filtros y búsqueda respetan paginación

---

## 📝 Notas Adicionales

- Los archivos se organizan automáticamente según el `folder_name`
- El tipo se extrae de las primeras letras antes del guion (CE, IN, SU)
- El mes/año se genera automáticamente al subir el archivo
- La paginación es configurable pero 15 es el valor recomendado

