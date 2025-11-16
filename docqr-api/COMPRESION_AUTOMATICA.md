# 📦 Sistema de Compresión Automática - DocQR

## Descripción

Sistema para comprimir automáticamente documentos antiguos en archivos ZIP organizados por mes y tipo de documento.

## Características

### ✅ Optimizaciones Implementadas

1. **Eliminación de PDFs originales**: 
   - Después de generar el PDF final con QR, se elimina el PDF original
   - Ahorra ~50% de espacio en disco

2. **Organización por mes en nombres**:
   - Formato: `{YYYY-MM}-{qr_id}-{nombre_original}.pdf`
   - Ejemplo: `2025-01-abc123...-documento.pdf`
   - Facilita la organización y compresión mensual

3. **Compresión automática por mes**:
   - Comprime documentos de meses anteriores
   - Organiza por tipo (CE, IN, SU) y mes
   - Guarda en: `storage/app/archived/{TIPO}/{MES}/{TIPO}-{MES}.zip`

## Estructura de Archivos

```
storage/app/
├── uploads/                    # PDFs originales (se eliminan después de procesar)
│   └── {TIPO}/{CODIGO}/
│       └── {YYYYMM}-{qr_id}-documento.pdf
│
├── qrcodes/                    # Imágenes QR
│   └── {qr_id}.png
│
├── final/                      # PDFs finales con QR
│   └── {TIPO}/
│       └── {random}-{YYYYMM}-{qr_id}-documento.pdf
│
└── archived/                   # ZIPs comprimidos (documentos antiguos)
    └── {TIPO}/
        └── {TIPO}-{YYYYMM}.zip
```

**Formato de nombres:**
- `{YYYYMM}` = Año y mes sin guión (ej: 202511, 202512, 202601)
- Ejemplo: `202511-abc123...-documento.pdf` (noviembre 2025)

## Uso del Comando de Compresión

### Comando Básico

```bash
php artisan documents:compress
```

Comprime documentos de hace más de 6 meses (por defecto).

### Opciones

```bash
# Comprimir documentos de hace más de 12 meses
php artisan documents:compress --months=12

# Ver qué se comprimiría sin hacerlo (dry-run)
php artisan documents:compress --dry-run

# Combinar opciones
php artisan documents:compress --months=12 --dry-run
```

## Programar Compresión Automática

### En Windows (Task Scheduler)

1. Crear archivo `compress.bat`:
```batch
@echo off
cd C:\xampp\htdocs\docqrgeofal\docqr-api
php artisan documents:compress --months=3
```

2. Programar en Task Scheduler para ejecutar mensualmente.

### En Linux (Cron)

Agregar a crontab (`crontab -e`):

```cron
# Comprimir documentos antiguos cada 6 meses (día 1 de enero y julio a las 2 AM)
0 2 1 1,7 * cd /ruta/a/docqr-api && php artisan documents:compress --months=6
```

## Proceso de Compresión

1. **Busca documentos completados** de meses anteriores
2. **Agrupa por tipo y mes** (CE/2025-01, IN/2025-01, etc.)
3. **Crea archivos ZIP** con todos los PDFs y QRs del grupo
4. **Elimina PDFs originales** (ya están en el ZIP)
5. **Marca como archivados** en la base de datos

## Ventajas

### 💾 Ahorro de Espacio

- **Antes**: 3 archivos por documento (original, QR, final) = ~3MB
- **Después**: 1 ZIP comprimido por mes = ~50-70% menos espacio
- **Ejemplo**: 1000 documentos = 3GB → 1.5GB comprimido

### ⚡ Rendimiento

- Menos archivos en el sistema de archivos
- Búsquedas más rápidas
- Backups más eficientes

### 📁 Organización

- Fácil encontrar documentos por mes
- ZIPs listos para descargar/backup
- Historial completo preservado

## Extracción de Documentos

Para extraer documentos de un ZIP:

```bash
# Navegar a la carpeta de archivos
cd storage/app/archived/CE/2025-01/

# Extraer ZIP
unzip CE-2025-01.zip -d extracted/

# O en Windows, usar WinRAR/7-Zip
```

## Migración de Base de Datos

Ejecutar la migración para agregar campos de archivado:

```bash
php artisan migrate
```

Esto agrega:
- `archived` (boolean): Si el documento está comprimido
- `archive_path` (string): Ruta del ZIP donde está guardado

## Recomendaciones

1. **Ejecutar cada 6 meses**: Programar para ejecutar semestralmente
2. **Mantener últimos 6 meses sin comprimir**: Para acceso rápido
3. **Backup de ZIPs**: Hacer backup de la carpeta `archived/` regularmente
4. **Monitoreo**: El sistema mostrará una notificación cuando haya documentos pendientes de compresión
5. **Notificaciones**: El frontend mostrará una campanita con alerta cuando sea necesario comprimir

## Ejemplo de Flujo Completo

1. **Usuario sube documento** (Noviembre 2025)
   - Se guarda en: `uploads/CE/CE-12345/202511-{qr_id}-doc.pdf`

2. **Usuario embebe QR**
   - Se genera: `final/CE/{random}-202511-{qr_id}-doc.pdf`
   - Se elimina: PDF original (ahorro de espacio)

3. **Mayo 2026 - Compresión automática (6 meses después)**
   - Comando busca documentos de Noviembre 2025 (hace más de 6 meses)
   - Crea: `archived/CE/CE-202511.zip`
   - Elimina: PDFs finales (ya están en ZIP)
   - Marca como archivados en BD
   - El sistema muestra notificación en el frontend cuando hay documentos pendientes

4. **Usuario necesita documento antiguo**
   - Sistema detecta que está archivado
   - Extrae del ZIP temporalmente o muestra opción de descarga

