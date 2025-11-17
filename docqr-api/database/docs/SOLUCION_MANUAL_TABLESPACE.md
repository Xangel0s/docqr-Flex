# Solución Manual para Tablespace Huérfano

Si el script SQL anterior no funciona, sigue estos pasos:

## Opción A: Eliminar archivos del tablespace manualmente

1. **Detén MySQL** desde XAMPP Control Panel

2. **Navega a la carpeta de datos de MySQL:**
   ```
   C:\xampp\mysql\data\eccohgon_docqr\
   ```

3. **Busca y elimina estos archivos** (si existen):
   - `qr_files.ibd`
   - `qr_files.frm`

4. **Inicia MySQL** nuevamente desde XAMPP Control Panel

5. **Ejecuta el script SQL** `SOLUCION_TABLESPACE.sql` en phpMyAdmin

## Opción B: Usar comandos MySQL directamente

Si tienes acceso a la línea de comandos de MySQL:

```sql
-- Conecta a MySQL
mysql -u root -p

-- Selecciona la base de datos
USE eccohgon_docqr;

-- Elimina la tabla si existe
DROP TABLE IF EXISTS qr_files;

-- Si aún da error, fuerza la eliminación del tablespace
-- (Esto requiere permisos de administrador)
SET GLOBAL innodb_force_recovery = 1;
DROP TABLE IF EXISTS qr_files;
SET GLOBAL innodb_force_recovery = 0;

-- Ahora crea la tabla
-- (Copia y pega el CREATE TABLE del script SOLUCION_TABLESPACE.sql)
```

## Opción C: Usar phpMyAdmin con operaciones manuales

1. En phpMyAdmin, selecciona la base de datos `eccohgon_docqr`
2. Ve a la pestaña "Operaciones"
3. Busca la tabla `qr_files` en la lista
4. Si aparece, haz clic en "Eliminar" o "Drop"
5. Luego ejecuta el CREATE TABLE del script

## Verificación

Después de cualquiera de estas opciones, verifica:

```sql
SHOW TABLES LIKE 'qr_files';
SHOW COLUMNS FROM qr_files;
```

Si todo está bien, deberías ver la tabla con todas sus columnas.

