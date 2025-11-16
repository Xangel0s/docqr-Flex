# 📁 Estructura de Almacenamiento - DocQR

## Ubicación de Archivos

Todos los archivos se guardan en: `storage/app/`

## Estructura de Carpetas

```
storage/app/
├── uploads/              # PDFs originales organizados por tipo
│   ├── CE/               # Certificados
│   │   └── CE-12345/     # Carpeta por código de documento
│   │       └── {qr_id}-documento.pdf
│   ├── IN/               # Informes de Ensayo
│   │   └── IN-ABC/
│   │       └── {qr_id}-documento.pdf
│   ├── SU/               # Suplementos
│   │   └── SU-XYZ/
│   │       └── {qr_id}-documento.pdf
│   └── OTROS/            # Documentos sin tipo definido
│       └── ...
│
├── qrcodes/              # Imágenes QR (sin organización por tipo)
│   └── {qr_id}.png
│
└── final/               # PDFs finales con QR embebido
    ├── CE/
    │   └── {random}-{qr_id}-documento.pdf
    ├── IN/
    │   └── {random}-{qr_id}-documento.pdf
    └── SU/
        └── {random}-{qr_id}-documento.pdf
```

## Ventajas de esta Organización

### ✅ Rendimiento
- **Búsqueda más rápida**: Con miles de archivos, tenerlos en una sola carpeta hace lenta la búsqueda
- **Mejor organización**: Los archivos están agrupados por tipo, facilitando el mantenimiento
- **Escalabilidad**: El sistema puede manejar miles de documentos sin degradación

### ✅ Mantenimiento
- **Fácil backup**: Puedes hacer backup solo de un tipo de documento
- **Limpieza selectiva**: Puedes eliminar documentos antiguos por tipo
- **Monitoreo**: Es más fácil ver cuántos documentos hay de cada tipo

### ✅ Espacio
- **Sin duplicación**: Cada archivo se guarda una vez
- **Optimización futura**: Fácil implementar compresión por carpeta si es necesario

## Ejemplo de Ruta Completa

**Documento**: Certificado CE-12345 (subido en noviembre 2025)
- **PDF Original**: `storage/app/uploads/CE/CE-12345/202511-abc123...-documento.pdf`
- **QR**: `storage/app/qrcodes/abc123....png`
- **PDF Final**: `storage/app/final/CE/xyz789-202511-abc123...-documento.pdf`
- **ZIP Archivado** (después de comprimir): `storage/app/archived/CE/CE-202511.zip`

## Consideraciones de Rendimiento

### ⚠️ Problemas Potenciales

1. **Muchos archivos en una carpeta**: 
   - Si tienes 10,000+ archivos en una sola carpeta, el sistema puede volverse lento
   - **Solución**: Organización por carpetas (ya implementada)

2. **Espacio en disco**:
   - Cada documento genera 3 archivos: PDF original, QR, PDF final
   - **Solución**: Considerar eliminar PDFs originales después de generar el final (opcional)

3. **Búsqueda en base de datos**:
   - Con muchos registros, las consultas pueden ser lentas
   - **Solución**: Índices en la base de datos (ya implementados)

### ✅ Optimizaciones Implementadas

1. **Índices en BD**: `qr_id`, `folder_name` están indexados
2. **Paginación**: Las listas de documentos usan paginación (15 por página)
3. **Soft Deletes**: Los documentos eliminados no se borran físicamente de inmediato
4. **Organización por carpetas**: Reduce el tiempo de búsqueda del sistema de archivos

## Recomendaciones Futuras

1. **Compresión**: Comprimir PDFs antiguos (>1 año) para ahorrar espacio
2. **CDN**: Para producción, usar CDN para servir archivos estáticos
3. **Almacenamiento en la nube**: Migrar a S3/Google Cloud Storage para escalabilidad
4. **Limpieza automática**: Eliminar PDFs originales después de X días si ya tienen PDF final

