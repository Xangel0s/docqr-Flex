# Guía de Prueba - Algoritmo Inteligente de Inyección QR

## Correcciones Aplicadas (2026-03-17)

### Bug Crítico Resuelto
El algoritmo `findSafePosition()` tenía un error de tipo de retorno que causaba:
- Solapamiento de QR con texto existente
- Posicionamiento incorrecto del código QR
- No respeto de márgenes y diseño del PDF

### Cambios Implementados

#### 1. Tipo de Retorno Corregido
```php
// ANTES (incorrecto):
private function findSafePosition(...): float

// AHORA (correcto):
private function findSafePosition(...): array
```

#### 2. Inicialización de Variables
```php
$result = [
    'x' => $x,
    'y' => $y,
    'width' => $w,
    'height' => $h
];
```

#### 3. Detección Mejorada de Colisiones
- Margen de seguridad: 5 → 10 unidades
- Separación de contenido: 25 → 30 unidades  
- Altura de texto: fontSize × 1.2 → fontSize × 1.5
- Detección dinámica de tamaño de fuente

#### 4. Textos Críticos Protegidos
El algoritmo ahora detecta y respeta:
- "Fin del Informe" (todas las variantes de mayúsculas/minúsculas)
- Firmas digitales
- Sellos y estampas

## Escenarios de Prueba

### Caso 1: Documento con "Fin del Informe"
**Esperado**: El QR debe aparecer **debajo** del texto "Fin del Informe" con un margen mínimo de 30 unidades.

**Pasos**:
1. Ir a Inyección Masiva
2. Subir un PDF de informe Geofal (con firma y "Fin del Informe")
3. En el Paso 2, abrir el Ubicador de QR
4. Colocar el QR en cualquier posición
5. Guardar y procesar el lote

**Validación**:
- ✅ El QR NO debe solaparse con "Fin del Informe"
- ✅ El QR NO debe solaparse con la firma
- ✅ El QR NO debe solaparse con el sello circular de Geofal
- ✅ Debe haber espacio visible entre el contenido y el QR

### Caso 2: Documento con Mucho Texto
**Esperado**: El QR debe posicionarse en el primer espacio en blanco disponible.

**Pasos**:
1. Subir un PDF con mucho contenido textual
2. Intentar colocar el QR sobre texto existente
3. El algoritmo debe reposicionar automáticamente

**Validación**:
- ✅ El QR se mueve automáticamente a espacio libre
- ✅ No solapa ningún texto
- ✅ Se mantiene dentro de los márgenes del PDF

### Caso 3: Documento con Poco Espacio
**Esperado**: El QR se redimensiona si no cabe con el tamaño original.

**Pasos**:
1. Usar un PDF muy lleno de contenido
2. El algoritmo debe reducir el tamaño del QR si es necesario

**Validación**:
- ✅ El QR se reduce automáticamente (mínimo 28pt)
- ✅ Sigue siendo escaneable
- ✅ No se sale de los límites del PDF

### Caso 4: Lote Masivo (10+ documentos)
**Esperado**: Todos los documentos deben procesarse correctamente con la plantilla.

**Pasos**:
1. Crear un lote de 10+ documentos
2. Configurar la plantilla en la Fila 1
3. Procesar todo el lote (Paso 3)

**Validación**:
- ✅ Todos los documentos procesados sin errores
- ✅ QR visible en todos los PDFs finales
- ✅ Sin solapamientos en ningún documento
- ✅ Posición consistente respetando el diseño de cada PDF

## Logs a Revisar

Buscar en `storage/logs/laravel.log`:

```
[INFO] Analizando posición segura para QR en página
[INFO] Colisión detectada con texto
[INFO] Texto crítico 'Fin del Informe' detectado
[INFO] Ajuste inteligente de posición: Y cambió de X a Y
[INFO] No se detectaron colisiones críticas
```

## Verificación Visual

Comparar ANTES vs DESPUÉS:

### ❌ ANTES (Incorrecto)
- QR solapaba "Fin del Informe"
- QR tapaba firmas y sellos
- QR sobre texto importante

### ✅ AHORA (Correcto)
- QR en espacio en blanco
- Respeta "Fin del Informe"
- No solapa firmas ni sellos
- Margen visible entre contenido y QR

## Comandos Útiles

```bash
# Ver logs en tiempo real
cd docqr-api
tail -f storage/logs/laravel.log | grep -i "safe position\|colisión\|ajuste"

# Limpiar cache si es necesario
php artisan cache:clear
php artisan config:clear
```

## Notas Técnicas

- El algoritmo usa `Smalot\PdfParser` para analizar el contenido textual
- Coordenadas en espacio estándar: 595x842 (A4)
- Sistema de coordenadas: Top-Left (TCPDF) vs Bottom-Left (Parser)
- Conversión automática entre sistemas de coordenadas

## Contacto

Si encuentras algún problema, revisar:
1. Logs del backend (`storage/logs/laravel.log`)
2. Consola del navegador (Frontend)
3. Estado de la base de datos (`qr_files.qr_position`)
