# DocQR

Sistema de gestión de documentos PDF con códigos QR para Geofal.

## Estructura

- `docqr-frontend/`: aplicación Angular.
- `docqr-api/`: API Laravel.

## Novedades del cambio actual

- `Fecha de emisión` obligatoria en los flujos de creación.
- Nuevo módulo `Subida Masiva IN`.
- Persistencia de borradores del lote masivo en `IndexedDB`.
- Visualización de `Fecha de emisión` en dashboard, listado, adjunto y editor.

## Desarrollo local

Backend:

```bash
cd docqr-api
cp .env.example .env
composer install
php artisan key:generate
php artisan migrate
php artisan serve
```

Frontend:

```bash
cd docqr-frontend
npm install
npm start
```

Configuración local recomendada:

- Base de datos MySQL local.
- Usuario `root`.
- Sin contraseña.
- Ver plantilla en `docqr-api/.env.example`.

## Producción

- Plantilla de variables: `docqr-api/.env.production.example`
- Guía de despliegue: `DESPLIEGUE_GEOFAL.md`

## Verificación mínima

1. Crear documento simple con `Fecha de emisión`.
2. Crear documento con flujo `Crear QR y Adjuntar PDF`.
3. Procesar un lote desde `Subida Masiva IN`.
4. Confirmar `Fecha de emisión` en `Mis Documentos` y dashboard.
