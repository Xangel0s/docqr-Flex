# 📦 Guía de Compresión Automática para cPanel

## Opciones para cPanel

### ✅ Opción 1: Cron Jobs (Recomendado)

cPanel tiene un gestor de Cron Jobs integrado que es la mejor opción para automatizar tareas.

#### Pasos:

1. **Acceder a Cron Jobs en cPanel**
   - Inicia sesión en cPanel
   - Busca "Cron Jobs" en el panel de control
   - O ve directamente a: `cpanel → Advanced → Cron Jobs`

2. **Configurar Cron Job**
   - **Frecuencia**: Cada 6 meses (día 1 de enero y julio)
   - **Comando**: 
   ```bash
   /usr/bin/php /home/tuusuario/public_html/docqr-api/artisan documents:compress --months=6
   ```
   
   **Configuración en cPanel:**
   - **Minuto**: `0`
   - **Hora**: `2` (2 AM)
   - **Día del mes**: `1`
   - **Mes**: `1,7` (enero y julio)
   - **Día de la semana**: `*` (cualquiera)

3. **Verificar ruta de PHP**
   ```bash
   which php
   ```
   Usa la ruta completa que te devuelva (puede ser `/usr/bin/php` o `/opt/cpanel/ea-php81/root/usr/bin/php`)

### ✅ Opción 2: Tareas Programadas de cPanel

Si tu hosting tiene "Tareas Programadas" o "Scheduled Tasks":

1. Crea una nueva tarea
2. Programa para ejecutar cada 6 meses
3. Comando:
   ```bash
   cd /home/tuusuario/public_html/docqr-api && php artisan documents:compress --months=6
   ```

### ✅ Opción 3: Script PHP Ejecutable

Si no puedes usar Cron Jobs, crea un script PHP que se ejecute manualmente o mediante un webhook:

**Archivo**: `docqr-api/public/compress.php`
```php
<?php
// Ejecutar compresión manualmente
// Acceder desde: https://tudominio.com/docqr-api/public/compress.php

// Proteger con contraseña (cambiar 'tu_password_secreto')
$password = $_GET['key'] ?? '';
if ($password !== 'tu_password_secreto') {
    die('Acceso denegado');
}

// Ejecutar comando
$output = [];
$return_var = 0;
exec('cd ' . __DIR__ . '/.. && php artisan documents:compress --months=6 2>&1', $output, $return_var);

header('Content-Type: text/plain; charset=utf-8');
echo "Resultado de compresión:\n\n";
echo implode("\n", $output);
```

**Uso**: `https://tudominio.com/docqr-api/public/compress.php?key=tu_password_secreto`

## Ejecutar Migración

### Opción 1: Desde Terminal SSH (Recomendado)

1. **Conectar por SSH** a tu servidor
2. **Navegar al directorio**:
   ```bash
   cd /home/tuusuario/public_html/docqr-api
   ```
3. **Ejecutar migración**:
   ```bash
   php artisan migrate
   ```

### Opción 2: Desde cPanel File Manager

1. **Abrir Terminal** en cPanel File Manager
2. **Navegar al directorio**:
   ```bash
   cd public_html/docqr-api
   ```
3. **Ejecutar migración**:
   ```bash
   php artisan migrate
   ```

### Opción 3: Desde phpMyAdmin (Manual)

Si no puedes usar Artisan, ejecuta el SQL directamente:

1. Abre phpMyAdmin
2. Selecciona tu base de datos: `eccohgon_docqr`
3. Ve a la pestaña "SQL"
4. Ejecuta:

```sql
ALTER TABLE `qr_files` 
ADD COLUMN `archived` BOOLEAN DEFAULT FALSE AFTER `status`,
ADD COLUMN `archive_path` VARCHAR(500) NULL AFTER `final_path`,
ADD INDEX `idx_archived_status` (`archived`, `status`);
```

## Verificar que Funciona

### Probar Compresión Manual

```bash
# Desde SSH o Terminal de cPanel
cd /home/tuusuario/public_html/docqr-api
php artisan documents:compress --dry-run
```

Esto mostrará qué se comprimiría sin hacerlo.

### Probar Compresión Real

```bash
php artisan documents:compress --months=6
```

## Recomendaciones para cPanel

1. **Usar Cron Jobs**: Es la opción más confiable
2. **Verificar permisos**: Asegúrate de que PHP tenga permisos para crear carpetas y archivos
3. **Límites de tiempo**: Si tienes muchos documentos, aumenta el `max_execution_time` en PHP
4. **Espacio en disco**: Verifica que tengas suficiente espacio antes de comprimir
5. **Backup**: Haz backup de la carpeta `storage/app/archived/` regularmente

## Solución de Problemas

### Error: "Permission denied"
```bash
chmod -R 755 storage/
chmod -R 755 bootstrap/cache/
```

### Error: "Class ZipArchive not found"
Instala la extensión ZIP de PHP (contacta a tu proveedor de hosting).

### Error: "Command not found"
Usa la ruta completa de PHP:
```bash
/usr/bin/php /ruta/completa/a/artisan documents:compress
```

## Monitoreo

El sistema mostrará notificaciones en el frontend cuando haya documentos pendientes de compresión. Puedes verificar manualmente desde:

- **Frontend**: Módulo "Compresión" (nuevo en el menú)
- **API**: `GET /api/system/compression-status`
- **Terminal**: `php artisan documents:compress --dry-run`

