# 📁 Estructura Automática de Carpetas por Fecha

## ✅ Sí, las Carpetas se Crean Automáticamente

### Cómo Funciona

El sistema usa `now()->format('Ym')` que genera dinámicamente el mes/año actual:

```php
$monthYear = now()->format('Ym'); // Genera: 202511, 202512, 202601, etc.
```

**Esto significa:**
- ✅ **Noviembre 2025:** Crea carpetas `202511`
- ✅ **Diciembre 2025:** Crea carpetas `202512` automáticamente
- ✅ **Enero 2026:** Crea carpetas `202601` automáticamente
- ✅ **Y así sucesivamente...**

### Creación Automática de Carpetas

Laravel's `Storage::makeDirectory()` crea **todas las subcarpetas necesarias** automáticamente:

```php
$storageFolder = "uploads/{$documentType}/{$monthYear}/{$qrId}";
Storage::disk('local')->makeDirectory($storageFolder);
// Crea: uploads/CE/202512/{qr_id}/ (si no existen)
```

**Ejemplo:**
- Si subes un PDF en **diciembre 2025**:
  - Sistema detecta: `now()->format('Ym')` = `202512`
  - Crea automáticamente: `uploads/CE/202512/{qr_id}/`
  - Guarda el PDF allí

### Estructura Resultante por Mes

```
uploads/
├── CE/
│   ├── 202511/          # Noviembre 2025 (creado automáticamente)
│   │   └── {qr_id}/
│   │       └── documento.pdf
│   └── 202512/          # Diciembre 2025 (se crea automáticamente cuando subes en dic)
│       └── {qr_id}/
│           └── documento.pdf
├── IN/
│   ├── 202511/
│   └── 202512/          # Se crea automáticamente
└── SU/
    ├── 202511/
    └── 202512/          # Se crea automáticamente
```

### Ventajas

1. **Sin Configuración Manual:**
   - No necesitas crear carpetas manualmente
   - El sistema las crea automáticamente al subir documentos

2. **Organización Temporal:**
   - Fácil identificar documentos por mes
   - Limpieza selectiva por período

3. **Escalabilidad:**
   - Cada mes tiene su carpeta
   - No se acumulan miles de archivos en una sola carpeta

4. **Backups Incrementales:**
   - Puedes hacer backup solo del mes actual
   - O comprimir meses antiguos

### Ejemplo Práctico

**Escenario:**
- **Hoy (Nov 2025):** Subes documento → Se guarda en `uploads/CE/202511/{qr_id}/`
- **Mañana (Dic 2025):** Subes documento → Se crea `uploads/CE/202512/` automáticamente → Se guarda allí
- **Enero 2026:** Subes documento → Se crea `uploads/CE/202601/` automáticamente → Se guarda allí

**No necesitas hacer nada manualmente.** ✅

### Verificación

Para verificar que funciona:
1. Sube un PDF hoy (mes actual)
2. Verifica que se crea la carpeta del mes actual
3. Espera al siguiente mes (o cambia la fecha del sistema para probar)
4. Sube otro PDF
5. Verifica que se crea la nueva carpeta del nuevo mes

---

## 📝 Notas Técnicas

- **Formato:** `YYYYMM` (6 dígitos)
  - `202511` = Noviembre 2025
  - `202512` = Diciembre 2025
  - `202601` = Enero 2026

- **Zona Horaria:** Usa la zona horaria configurada en Laravel (`config/app.php`)

- **Permisos:** Las carpetas se crean con los permisos del sistema (normalmente 755)

