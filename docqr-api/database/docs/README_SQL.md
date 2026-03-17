# 📁 Organización de Archivos SQL

## 📂 **ESTRUCTURA DE CARPETAS**

```
database/
├── sql/              # 📄 Todos los archivos SQL
├── scripts/          # 🔧 Scripts PHP de utilidad
├── docs/             # 📚 Documentación (archivos .md)
└── migrations/       # 🗄️ Migraciones de Laravel
```

---

## ✅ **ARCHIVOS ÚTILES (MANTENER)**

### 🎯 **Para Producción - Usar estos:**

1. **`sql/CREAR_BASE_DATOS_COMPLETA.sql`** ⭐ **RECOMENDADO**
   - ✅ Script completo y actualizado
   - ✅ Crea todas las tablas necesarias: `migrations`, `qr_files`, `sessions`, `document`
   - ✅ Registra todas las migraciones automáticamente
   - ✅ Incluye verificaciones y resumen
   - **USO:** Ejecutar cuando necesites crear la base de datos desde cero

2. **`sql/CREAR_TABLA_SESSIONS.sql`** ⭐ **NUEVO**
   - ✅ Crea solo la tabla `sessions` si falta
   - ✅ Soluciona el error: "Table 'sessions' doesn't exist"
   - **USO:** Ejecutar si obtienes error 500 relacionado con sesiones

---

## ⚠️ **ARCHIVOS OBSOLETOS (NO USAR)**

### ❌ **Scripts antiguos o incompletos:**

1. **`RECREAR_TODO_DESDE_CERO.sql`**
   - ⚠️ Versión antigua de `CREAR_BASE_DATOS_COMPLETA.sql`
   - ❌ No usar, usar `CREAR_BASE_DATOS_COMPLETA.sql` en su lugar

2. **`APLICAR_MIGRACIONES_COMPLETO.sql`**
   - ⚠️ Solo registra migraciones, no crea tablas
   - ❌ No usar, `CREAR_BASE_DATOS_COMPLETA.sql` ya lo incluye

3. **`SOLUCION_QR_FILES.sql`**
   - ⚠️ Script de solución temporal
   - ❌ No usar, problema ya resuelto en `CREAR_BASE_DATOS_COMPLETA.sql`

4. **`SOLUCION_TABLESPACE.sql`**
   - ⚠️ Script de solución temporal para problemas de tablespace
   - ❌ No usar, problema ya resuelto

5. **`CREAR_QR_FILES.sql`**
   - ⚠️ Solo crea la tabla `qr_files`
   - ❌ No usar, `CREAR_BASE_DATOS_COMPLETA.sql` ya lo incluye

6. **`REGISTRAR_MIGRACIONES.sql`**
   - ⚠️ Solo registra migraciones
   - ❌ No usar, `CREAR_BASE_DATOS_COMPLETA.sql` ya lo incluye

7. **`REGISTRAR_MIGRACIONES_SIMPLE.sql`**
   - ⚠️ Versión simple de registro de migraciones
   - ❌ No usar, `CREAR_BASE_DATOS_COMPLETA.sql` ya lo incluye

8. **`REGISTRAR_MIGRACIONES_ALTERNATIVA.sql`**
   - ⚠️ Alternativa de registro de migraciones
   - ❌ No usar, `CREAR_BASE_DATOS_COMPLETA.sql` ya lo incluye

9. **`SOLO_FALTANTES.sql`**
   - ⚠️ Crea solo tablas faltantes
   - ❌ No usar, usar `CREAR_BASE_DATOS_COMPLETA.sql` o `CREAR_TABLA_SESSIONS.sql`

10. **`VERIFICAR_DOCUMENT.sql`**
    - ⚠️ Script de verificación
    - ❌ No usar, solo para debugging

11. **`migrations_sql.sql`**
    - ⚠️ Versión antigua de migraciones
    - ❌ No usar, `CREAR_BASE_DATOS_COMPLETA.sql` ya lo incluye

12. **`migrations_sql_compatible.sql`**
    - ⚠️ Versión compatible de migraciones
    - ❌ No usar, `CREAR_BASE_DATOS_COMPLETA.sql` ya lo incluye

13. **`eccohgon_docqr.sql`**
    - ⚠️ Backup o exportación antigua
    - ❌ No usar, puede tener datos desactualizados

---

## 📋 **GUÍA DE USO**

### **Escenario 1: Crear base de datos desde cero**
```sql
-- Ejecutar en phpMyAdmin:
-- 1. Abrir phpMyAdmin
-- 2. Seleccionar la base de datos o crear nueva
-- 3. Ir a la pestaña "SQL"
-- 4. Copiar y pegar el contenido de: database/sql/CREAR_BASE_DATOS_COMPLETA.sql
-- 5. Ejecutar
```

### **Escenario 2: Error "Table 'sessions' doesn't exist"**
```sql
-- Ejecutar en phpMyAdmin:
-- 1. Abrir phpMyAdmin
-- 2. Seleccionar la base de datos eccohgon_docqr
-- 3. Ir a la pestaña "SQL"
-- 4. Copiar y pegar el contenido de: database/sql/CREAR_TABLA_SESSIONS.sql
-- 5. Ejecutar
```

### **Escenario 3: Verificar tablas existentes**
```sql
-- En phpMyAdmin, ejecutar:
SHOW TABLES;

-- Deberías ver:
-- - migrations
-- - qr_files
-- - sessions
-- - document (opcional)
```

---

## 🔍 **VERIFICACIÓN POST-EJECUCIÓN**

Después de ejecutar cualquier script, verifica:

```sql
-- 1. Ver todas las tablas
SHOW TABLES;

-- 2. Ver estructura de qr_files
SHOW COLUMNS FROM `qr_files`;

-- 3. Ver estructura de sessions
SHOW COLUMNS FROM `sessions`;

-- 4. Ver migraciones registradas
SELECT * FROM `migrations` ORDER BY `batch`, `migration`;

-- 5. Contar registros (deberían ser 0 en tablas nuevas)
SELECT 
    (SELECT COUNT(*) FROM `qr_files`) as total_qr_files,
    (SELECT COUNT(*) FROM `sessions`) as total_sessions,
    (SELECT COUNT(*) FROM `migrations`) as total_migrations;
```

---

## 📝 **NOTAS IMPORTANTES**

1. **Siempre hacer backup** antes de ejecutar scripts SQL
2. **`CREAR_BASE_DATOS_COMPLETA.sql`** es el script más completo y actualizado
3. **`CREAR_TABLA_SESSIONS.sql`** es solo para solucionar el error de sesiones
4. Los archivos obsoletos se pueden eliminar, pero se mantienen por referencia histórica
5. Si tienes datos existentes, haz backup antes de ejecutar scripts que crean tablas

---

## 🗑️ **ARCHIVOS PARA ELIMINAR (OPCIONAL)**

Si quieres limpiar, puedes eliminar estos archivos obsoletos de `sql/`:
- `sql/RECREAR_TODO_DESDE_CERO.sql`
- `sql/APLICAR_MIGRACIONES_COMPLETO.sql`
- `sql/SOLUCION_QR_FILES.sql`
- `sql/SOLUCION_TABLESPACE.sql`
- `sql/CREAR_QR_FILES.sql`
- `sql/REGISTRAR_MIGRACIONES.sql`
- `sql/REGISTRAR_MIGRACIONES_SIMPLE.sql`
- `sql/REGISTRAR_MIGRACIONES_ALTERNATIVA.sql`
- `sql/SOLO_FALTANTES.sql`
- `sql/VERIFICAR_DOCUMENT.sql`
- `sql/migrations_sql.sql`
- `sql/migrations_sql_compatible.sql`
- `sql/eccohgon_docqr.sql`

**Mantener solo:**
- ✅ `sql/CREAR_BASE_DATOS_COMPLETA.sql`
- ✅ `sql/CREAR_TABLA_SESSIONS.sql`
- ✅ `docs/README_SQL.md` (este archivo)

