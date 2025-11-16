# 📋 Flujo Completo de Subida de Documentos - Estado Actual

## ✅ Implementado

### 1. **Subida de PDF** ✅
- Usuario selecciona archivo (drag & drop o click)
- Validación: PDF, 1 página, máximo 10MB
- Validación de campos: Tipo de documento, Código
- Botón "Continuar" deshabilitado hasta completar validaciones

### 2. **Generación de QR** ✅
- Se genera `qr_id` único (32 caracteres)
- Se crea URL: `/api/view/{qr_id}`
- Se genera imagen QR (PNG)
- Si falla → NO se guarda en BD

### 3. **Guardado en Base de Datos** ✅
- Se guarda con `status = 'uploaded'` (Pendiente)
- Se guarda con `scan_count = 0`
- Se organiza por tipo y mes: `uploads/{TIPO}/{CODIGO}/`
- Nombre con formato: `{YYYYMM}-{qr_id}-archivo.pdf`

### 4. **Redirección al Editor** ✅
- Después de subir exitosamente, redirige a `/editor/{qr_id}`
- Muestra mensaje de éxito

---

## ❌ Falta Implementar

### 5. **Editor de PDF** ❌ (CRÍTICO)

**Lo que debe hacer:**
1. **Cargar PDF**: Mostrar el PDF subido usando `ngx-extended-pdf-viewer`
2. **Mostrar QR**: Mostrar la imagen QR como elemento draggable sobre el PDF
3. **Arrastrar QR**: Permitir arrastrar el QR con el mouse (drag & drop)
4. **Escalar QR**: Permitir cambiar el tamaño del QR (escala)
5. **Validar límites**: Asegurar que el QR no se salga del PDF
6. **Vista previa**: Mostrar cómo quedará el PDF final
7. **Guardar posición**: Botón para embebir QR en el PDF
8. **Llamar API**: Enviar posición a `/api/embed` para procesar

**Componentes necesarios:**
- `ngx-extended-pdf-viewer` para mostrar PDF (ya en package.json)
- `@angular/cdk/drag-drop` para arrastrar QR (ya importado)
- Lógica de posicionamiento y escalado
- Validación de límites del PDF

---

## 🔄 Flujo Completo Esperado

```
1. Usuario sube PDF ✅
   ↓
2. Sistema genera QR ✅
   ↓
3. Guarda en BD (status: 'uploaded') ✅
   ↓
4. Redirige a /editor/{qr_id} ✅
   ↓
5. Editor carga PDF ❌ (FALTA)
   ↓
6. Usuario arrastra QR ❌ (FALTA)
   ↓
7. Usuario escala QR ❌ (FALTA)
   ↓
8. Usuario guarda posición ❌ (FALTA)
   ↓
9. Sistema embebe QR en PDF ✅ (Backend listo)
   ↓
10. Cambia status a 'completed' ✅
```

---

## 📝 Resumen

**Completado:**
- ✅ Subida de PDF
- ✅ Generación de QR
- ✅ Guardado en BD
- ✅ Redirección al editor
- ✅ Backend para embebido (listo)

**Falta:**
- ❌ **Editor de PDF completo** (renderizar PDF, QR draggable, guardar posición)

El backend está listo, solo falta implementar el componente del editor en el frontend.

