# DocQR

Sistema de gestion de documentos PDF con codigos QR para Geofal.

## Estructura

- `docqr-frontend/`: aplicacion Angular.
- `docqr-api/`: API Laravel.
- `deploy/`: paquetes y manuales de despliegue.

## Novedades del cambio actual

- `Fecha de emision` obligatoria en los flujos de creacion.
- Nuevo modulo `Subida Masiva IN`.
- Persistencia de borradores del lote masivo en `IndexedDB`.
- Visualizacion de `Fecha de emision` en dashboard, listado, adjunto y editor.

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

Configuracion local recomendada:

- Base de datos MySQL local.
- Usuario `root`.
- Sin contrasena.
- Ver plantilla en `docqr-api/.env.example`.

## Produccion

- Plantilla de variables: `docqr-api/.env.example`
- Guia de despliegue: `DESPLIEGUE_GEOFAL.md`

## Verificacion minima

1. Crear documento simple con `Fecha de emision`.
2. Crear documento con flujo `Crear QR y Adjuntar PDF`.
3. Procesar un lote desde `Subida Masiva IN`.
4. Confirmar `Fecha de emision` en `Mis Documentos` y dashboard.
