# DocQR PDF Analyzer - Microservicio Python

Microservicio de análisis inteligente de PDFs para el módulo de inyección masiva de QR.

## Tecnologías
- **FastAPI**: Framework web async de alto rendimiento
- **PyMuPDF (fitz)**: Análisis de PDFs con detección precisa de elementos
- **Python 3.9+**

## Instalación

```bash
cd docqr-pdf-analyzer
python -m venv venv
venv\Scripts\activate   # Windows
pip install -r requirements.txt
```

## Ejecución

```bash
python run.py
# Servidor en http://127.0.0.1:8001
```

## Endpoints

### `GET /health`
Verificar estado del servicio.

### `POST /analyze`
Analizar un PDF y devolver todos los elementos detectados.
- **Parámetros**: `file` (PDF), `page_number` (int)
- **Respuesta**: texto, imágenes, gráficos con bounding boxes exactos

### `POST /find-safe-position`
Encontrar posición segura para QR respetando la región del usuario.
- **Parámetros**: `file` (PDF), `x`, `y`, `width`, `height`, `page_number`, `page_width`, `page_height`
- **Respuesta**: posición y tamaño ajustados

## Integración con Laravel
Laravel llama a `POST /find-safe-position` desde `PdfProcessorService.php` antes de inyectar el QR.
