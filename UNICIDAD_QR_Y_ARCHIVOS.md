# 🔐 Unicidad de QR y Archivos - Análisis

## ✅ Confirmación: Sistema de Unicidad

### 1. **QR ID (qr_id) - ÚNICO POR DOCUMENTO**

**Generación:**
```php
// Genera un string aleatorio de 32 caracteres
$qrId = Str::random(32);
```

**Garantías de Unicidad:**
- ✅ **Constraint UNIQUE en BD**: `$table->string('qr_id', 32)->unique()->index()`
- ✅ **Verificación antes de guardar**: Ahora verifica que no exista antes de crear
- ✅ **32 caracteres aleatorios**: Probabilidad de colisión extremadamente baja
- ✅ **Cada documento = QR único**: Incluso si subes el mismo PDF dos veces, tendrá QR diferente

**Probabilidad de Colisión:**
- 32 caracteres alfanuméricos = 62^32 combinaciones posibles
- Probabilidad de duplicado: ~1 en 2.27 × 10^57
- **Prácticamente imposible** que se repita

---

### 2. **Nombres de Archivos - ÚNICOS**

**Formato del nombre:**
```
{YYYYMM}-{qr_id}-{nombre_original}
Ejemplo: 202511-kDUtDDVDlohIupfVNv2Y0Potpfj3eeUZ-ERICKJULIANCV.pdf
```

**Componentes:**
1. **YYYYMM** (6 dígitos): Mes y año de creación
2. **qr_id** (32 caracteres): ID único del QR (único por documento)
3. **nombre_original**: Nombre original del archivo subido

**Garantías de Unicidad:**
- ✅ **qr_id es único** → El nombre del archivo también será único
- ✅ **Incluso el mismo archivo subido 2 veces** tendrá nombres diferentes
- ✅ **Incluso en el mismo mes** tendrá nombres diferentes (por el qr_id único)

**Ejemplo:**
```
Primera subida: 202511-abc123...xyz-ERICKJULIANCV.pdf
Segunda subida: 202511-def456...uvw-ERICKJULIANCV.pdf
```
→ **Nombres diferentes** aunque sea el mismo archivo

---

### 3. **URLs del QR - ÚNICAS**

**Formato:**
```
http://tudominio.com/api/view/{qr_id}
```

**Garantías:**
- ✅ **qr_id es único** → La URL también será única
- ✅ **Cada documento tiene su propia URL** para escanear
- ✅ **No hay conflictos** entre documentos

---

## 📊 Resumen de Unicidad

| Elemento | Garantía | Método |
|----------|----------|--------|
| **qr_id** | ✅ ÚNICO | `Str::random(32)` + Constraint UNIQUE + Verificación |
| **Nombre archivo PDF** | ✅ ÚNICO | Incluye `qr_id` único |
| **Nombre archivo QR** | ✅ ÚNICO | `{qr_id}.png` (qr_id es único) |
| **URL del QR** | ✅ ÚNICA | Incluye `qr_id` único |
| **Ruta final PDF** | ✅ ÚNICA | Incluye nombre único con `qr_id` |

---

## 🔄 Flujo de Unicidad

```
1. Usuario sube PDF
   ↓
2. Sistema genera qr_id único (32 caracteres aleatorios)
   ↓
3. Verifica que no exista en BD (nuevo)
   ↓
4. Si existe, genera otro (loop hasta encontrar uno único)
   ↓
5. Guarda en BD con constraint UNIQUE
   ↓
6. Nombre archivo: {YYYYMM}-{qr_id}-{original}
   → ÚNICO porque qr_id es único
   ↓
7. QR guardado como: {qr_id}.png
   → ÚNICO porque qr_id es único
```

---

## ✅ Respuesta a tu Pregunta

**¿El QR varía según el documento?**
- ✅ **SÍ**: Cada documento tiene su propio `qr_id` único
- ✅ **SÍ**: Incluso el mismo archivo subido 2 veces tendrá QR diferente
- ✅ **SÍ**: Cada QR apunta a una URL única

**¿El nombre de archivos nunca se repetirá?**
- ✅ **CORRECTO**: Los nombres de archivos **NUNCA se repetirán**
- ✅ **Razón**: Incluyen el `qr_id` que es único
- ✅ **Garantía**: Constraint UNIQUE en BD + verificación antes de guardar

---

## 🛡️ Protecciones Implementadas

1. **Constraint UNIQUE en BD** - La BD rechaza duplicados
2. **Verificación antes de guardar** - Código verifica que no exista
3. **32 caracteres aleatorios** - Probabilidad de colisión prácticamente cero
4. **Nombres incluyen qr_id** - Garantiza unicidad de archivos

**Conclusión: El sistema garantiza unicidad total de QR y archivos.**

