# Instrucciones para Aplicar Migraciones Correctamente

## Problema Detectado

Tanto la tabla `migrations` como `qr_files` tienen problemas de tablespace huérfano en MySQL. Esto requiere una solución manual.

## Solución Paso a Paso

### Paso 1: Detener MySQL (si es necesario)

Si los scripts PHP no funcionan, detén MySQL desde XAMPP Control Panel.

### Paso 2: Eliminar Archivos de Tablespace (si es necesario)

Si el problema persiste, elimina manualmente:

1. Ve a: `C:\xampp\mysql\data\eccohgon_docqr\`
2. Elimina estos archivos si existen:
   - `migrations.ibd`
   - `migrations.frm`
   - `qr_files.ibd` (si ya lo eliminaste antes, está bien)

### Paso 3: Iniciar MySQL

Inicia MySQL desde XAMPP Control Panel.

### Paso 4: Ejecutar Script SQL en phpMyAdmin

1. Abre phpMyAdmin: http://localhost/phpmyadmin
2. Selecciona la base de datos: `eccohgon_docqr`
3. Ve a la pestaña **SQL**
4. Abre el archivo: `docqr-api/database/APLICAR_MIGRACIONES_COMPLETO.sql`
5. Copia TODO el contenido del archivo
6. Pégalo en el área de SQL de phpMyAdmin
7. Haz clic en **Continuar** o **Ejecutar**

### Paso 5: Verificar

Después de ejecutar el script, verifica que todo está correcto:

```sql
-- Ver migraciones registradas
SELECT * FROM migrations WHERE migration LIKE '%qr_files%';

-- Verificar tabla qr_files
SHOW COLUMNS FROM qr_files;
```

Deberías ver:
- 3 migraciones registradas en la tabla `migrations`
- La tabla `qr_files` con todas sus columnas

## ¿Qué hace este script?

1. **Recrea la tabla `migrations`**: Elimina y vuelve a crear la tabla para resolver problemas de tablespace
2. **Calcula el siguiente batch**: Determina qué número de batch usar para las nuevas migraciones
3. **Registra las 3 migraciones de qr_files**:
   - `2025_11_15_000000_create_qr_files_table` - Crear la tabla
   - `2025_11_16_000000_add_archived_fields_to_qr_files` - Agregar campos archived
   - `2025_11_16_000001_add_file_deleted_at_to_qr_files` - Agregar campo original_file_deleted_at

## Después de Aplicar

Una vez que ejecutes el script:

1. ✅ Las migraciones estarán registradas correctamente
2. ✅ Laravel sabrá que estas migraciones ya están aplicadas
3. ✅ Podrás usar `php artisan migrate:status` sin errores
4. ✅ El sistema funcionará correctamente

## Verificación con Laravel

Después de aplicar el script, puedes verificar con:

```bash
php artisan migrate:status
```

Deberías ver las 3 migraciones de qr_files marcadas como ejecutadas.

