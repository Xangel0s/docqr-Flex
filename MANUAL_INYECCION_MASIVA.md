# Manual de Inyección QR Masiva - DocQR

Este documento explica el flujo de trabajo para la inyección masiva de códigos QR utilizando el nuevo sistema guiado por pasos.

## Flujo de Trabajo en 3 Pasos

### Paso 1: Configuración del Lote
En este paso se define la estructura inicial del envío masivo.
1.  **Cantidad de Documentos:** Indica cuántos documentos vas a procesar (máximo 50 por lote).
2.  **Carga de Archivos:** 
    *   Puedes subir los archivos uno a uno en cada fila.
    *   O utilizar la **Carga Masiva de PDFs** para soltar varios archivos a la vez; el sistema los repartirá automáticamente entre las filas vacías.

### Paso 2: Definición de Plantilla (Documento Guía)
Para que el sistema sepa dónde colocar el QR en 50 documentos distintos, necesita una referencia. Esa referencia es siempre la **Fila 1**.
1.  **Asignar Datos:** Completa el código y la fecha de la Fila 1.
2.  **Abrir Ubicador:** Haz clic en "Abrir Ubicador". Se abrirá el editor visual sobre el PDF de la primera fila.
3.  **Posicionar y Guardar:** Mueve el QR a la posición deseada y guarda.
4.  **Sincronización:** Al volver, el sistema mostrará "Inyección Lista" en la muestra. Esa coordenada (X, Y y página) es la que se usará para el resto del lote.

### Paso 3: Inyección y Resultados
Una vez configurada la plantilla, puedes proceder a la ejecución.
1.  **Completar Datos del Lote:** Ingresa los Códigos IN y Fechas de Emisión de los demás documentos directamente en la lista. 
    > [!IMPORTANT]
    > **Un documento solo se procesará si tiene AMBAS cosas: el archivo PDF subido y su Código IN escrito.** Si falta alguno de los dos, el sistema te avisará que no hay documentos "Listos" para procesar.
    > [!NOTE]
    > No es obligatorio completar todas las filas si solo quieres procesar unas pocas en este momento.
2.  **Inyectar Lote:** Haz clic en "Finalizar e Inyectar Lote". El sistema:
    *   Subirá los PDFs que aún no estén en el servidor.
    *   Inyectará el código QR en la posición exacta de la plantilla.
3.  **Validación Visual:** Cada fila exitosa mostrará un botón de **Vista Previa** (icono de ojo). Púlsalo para verificar que el QR se inyectó correctamente en el lugar esperado.
4.  **Descarga:** Puedes descargar los documentos procesados individualmente o esperar a que termine el lote para descargarlos todos.

---
## Reglas de Validación
*   **Archivos Duplicados:** El sistema te avisará si intentas subir el mismo PDF en dos filas distintas.
*   **Códigos Existentes:** El sistema verifica en tiempo real si un código IN ya ha sido utilizado previamente para evitar duplicados en la base de datos.
*   **Procesamiento Inteligente:** Si una fila no tiene código o no tiene PDF, el sistema simplemente la saltará y continuará con las demás, permitiéndote trabajar de forma parcial.
