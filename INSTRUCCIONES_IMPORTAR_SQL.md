# Instrucciones para Importar SQL en XAMPP

## 📋 Archivos SQL Disponibles

1. **`migrations_sql_compatible.sql`** - ✅ **RECOMENDADO** (Compatible con todas las versiones)
2. `migrations_sql.sql` - Versión con IF NOT EXISTS (requiere MySQL 5.7+)

## 🚀 Pasos para Importar

### Opción 1: Usando phpMyAdmin (Recomendado)

1. **Abre phpMyAdmin**: `http://localhost/phpmyadmin`

2. **Selecciona la base de datos**: `eccohgon_docqr`

3. **Ve a la pestaña "SQL"**

4. **Copia y pega el contenido** de `migrations_sql_compatible.sql`

5. **Haz clic en "Continuar"** o presiona `Ctrl+Enter`

6. **Verifica que no haya errores** (si alguna columna ya existe, es normal que muestre un warning)

### Opción 2: Usando línea de comandos

```powershell
# Desde la carpeta del proyecto
cd C:\xampp\htdocs\docqrgeofal\docqr-api\database

# Importar SQL
mysql -u root -p eccohgon_docqr < migrations_sql_compatible.sql
```

## ✅ Verificar que se crearon las tablas

Ejecuta en phpMyAdmin:

```sql
SHOW TABLES;
```

Deberías ver:
- ✅ `document` (tabla existente, ahora con columnas nuevas)
- ✅ `qr_files` (tabla nueva)
- ✅ `sessions` (tabla nueva)
- ✅ `migrations` (tabla nueva)

## 🔍 Verificar columnas agregadas a `document`

```sql
DESCRIBE document;
```

Deberías ver las nuevas columnas:
- `qr_path`
- `final_path`
- `qr_position`
- `qr_status`
- `scan_count`
- `last_scanned_at`
- `folder_name`

## ⚠️ Notas Importantes

- Si alguna columna ya existe, el comando `ALTER TABLE` fallará con un error
- Esto es **normal** si ya ejecutaste las migraciones antes
- Simplemente ignora esos errores o comenta las líneas que ya existen

## 🐛 Solución de Problemas

### Error: "Table already exists"
- **Solución**: La tabla ya existe, está bien. Continúa con las siguientes.

### Error: "Duplicate column name"
- **Solución**: La columna ya existe en la tabla `document`. Comenta esa línea del SQL.

### Error: "Unknown database"
- **Solución**: Crea la base de datos primero:
  ```sql
  CREATE DATABASE eccohgon_docqr CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
  ```

## 📝 Después de Importar

1. Verifica que Laravel pueda conectarse:
   ```powershell
   php artisan migrate:status
   ```

2. Prueba el servidor:
   ```powershell
   php artisan serve
   ```

3. Abre: `http://localhost:8000`

¡Debería funcionar sin errores! 🎉

