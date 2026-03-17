# Despliegue Geofal

Esta guía cubre el cambio que introduce `Fecha de emisión` obligatoria y el nuevo módulo `Subida Masiva IN`.

## 1. Preparación

Antes de desplegar:

1. Respaldar base de datos.
2. Respaldar `docqr-api/.env` y la build activa del frontend.
3. Confirmar espacio suficiente para almacenamiento de PDFs.

Ejemplo de backup MySQL:

```bash
mysqldump -u root -p docqr_production > backup-docqr-antes-emission-date.sql
```

## 2. Variables de entorno

Backend:

- Para local usar `docqr-api/.env.example`.
- Para producción usar `docqr-api/.env.production.example` como plantilla.

Valores mínimos a revisar:

- `APP_ENV`
- `APP_DEBUG`
- `APP_URL`
- `FRONTEND_URL`
- `DB_HOST`
- `DB_PORT`
- `DB_DATABASE`
- `DB_USERNAME`
- `DB_PASSWORD`
- `MAX_FILE_SIZE`

No versionar credenciales reales.

## 3. Backend

Desplegar código y ejecutar:

```bash
cd docqr-api
composer install --no-dev --optimize-autoloader
cp .env.production.example .env
php artisan key:generate --force
php artisan migrate --force
php artisan optimize:clear
php artisan optimize
```

La migración nueva agrega `emission_date` a `qr_files` y a la tabla legacy `document` si existe.

## 4. Frontend

Compilar y publicar:

```bash
cd docqr-frontend
npm install
npm run build
```

Publicar el contenido generado en `dist/` según el servidor usado.

## 5. Smoke Tests

Validar después del despliegue:

1. Crear documento desde `Insertar QR en PDF` con `Fecha de emisión`.
2. Crear documento desde `Crear QR y Adjuntar PDF` con `Fecha de emisión`.
3. Confirmar que sin `Fecha de emisión` el formulario queda bloqueado.
4. Entrar a `Subida Masiva IN`, generar 3 filas y guardar borrador.
5. Recargar la página y restaurar el borrador.
6. Procesar 3 filas válidas en `Subida Masiva IN`.
7. Procesar un lote con 1 código repetido y validar error parcial.
8. Revisar `Mis Documentos` y confirmar columna `Fecha de emisión`.
9. Revisar dashboard y confirmar `Fecha de emisión` en recientes.
10. Consultar `GET /api/documents/check-code` autenticado y validar respuesta.

## 6. Cambios incluidos

- Nuevo campo obligatorio `emission_date` para documentos nuevos.
- Nuevo módulo frontend `Subida Masiva IN`.
- Persistencia de borrador del módulo masivo en `IndexedDB`.
- Exposición de `emission_date` en listado, dashboard, adjunto y editor.
- Ruta `GET /documents/check-code` registrada en backend.

## 7. Rollback

Si el despliegue falla:

1. Restaurar build anterior del frontend.
2. Restaurar código previo del backend.
3. Restaurar `.env` anterior si fue modificado.
4. Restaurar base de datos desde backup si la migración ya se ejecutó.

Rollback de base de datos solo si es necesario y con respaldo confirmado:

```bash
cd docqr-api
php artisan migrate:rollback --step=1
```

Si ya existen registros nuevos usando `emission_date`, evaluar primero el impacto antes del rollback.
