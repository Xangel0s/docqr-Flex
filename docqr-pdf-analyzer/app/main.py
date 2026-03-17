"""
DocQR PDF Analyzer - Microservicio de análisis inteligente de PDFs
Detecta texto, imágenes, gráficos y calcula zonas seguras para inyección de QR
"""

import os
import tempfile
from fastapi import FastAPI, UploadFile, File, Form
from fastapi.middleware.cors import CORSMiddleware
from pydantic import BaseModel
from typing import Optional
from app.analyzer import PDFAnalyzer

app = FastAPI(
    title="DocQR PDF Analyzer",
    description="Microservicio de análisis inteligente de PDFs para inyección de QR",
    version="1.0.0"
)

app.add_middleware(
    CORSMiddleware,
    allow_origins=["http://127.0.0.1:8000", "http://localhost:8000"],
    allow_methods=["*"],
    allow_headers=["*"],
)


class SafePositionRequest(BaseModel):
    """Modelo para solicitud de posición segura"""
    x: float
    y: float
    width: float
    height: float
    page_number: int = 1
    page_width: float = 595.0
    page_height: float = 842.0


@app.get("/health")
def health_check():
    """Verificar que el servicio está activo"""
    return {"status": "ok", "service": "docqr-pdf-analyzer"}


@app.post("/analyze")
async def analyze_pdf(
    file: UploadFile = File(...),
    page_number: int = Form(1)
):
    """
    Analizar un PDF y devolver todos los elementos detectados con coordenadas exactas.
    Detecta: texto, imágenes, gráficos, líneas.
    """
    tmp_path = None
    try:
        # Guardar archivo temporal
        with tempfile.NamedTemporaryFile(delete=False, suffix=".pdf") as tmp:
            content = await file.read()
            tmp.write(content)
            tmp_path = tmp.name

        analyzer = PDFAnalyzer(tmp_path)
        result = analyzer.analyze_page(page_number)
        del analyzer
        return {"success": True, "data": result}

    except Exception as e:
        return {"success": False, "error": str(e)}
    finally:
        try:
            if tmp_path and os.path.exists(tmp_path):
                os.unlink(tmp_path)
        except PermissionError:
            pass


@app.post("/find-safe-position")
async def find_safe_position(
    file: UploadFile = File(...),
    x: float = Form(...),
    y: float = Form(...),
    width: float = Form(...),
    height: float = Form(...),
    page_number: int = Form(1),
    page_width: float = Form(595.0),
    page_height: float = Form(842.0)
):
    """
    Encontrar posición segura para QR respetando la región elegida por el usuario.
    
    - Detecta todos los obstáculos en el PDF (texto, imágenes, gráficos)
    - Calcula la posición óptima DENTRO de la región elegida
    - Reduce el tamaño del QR si es necesario
    - Devuelve la posición y tamaño ajustados
    """
    import traceback
    tmp_path = None
    try:
        with tempfile.NamedTemporaryFile(delete=False, suffix=".pdf") as tmp:
            content = await file.read()
            tmp.write(content)
            tmp_path = tmp.name

        print(f"[DEBUG] PDF guardado en: {tmp_path}, size: {len(content)} bytes")
        print(f"[DEBUG] Params: x={x}, y={y}, w={width}, h={height}, page={page_number}")

        analyzer = PDFAnalyzer(tmp_path)
        result = analyzer.find_safe_position(
            page_number=page_number,
            qr_x=x,
            qr_y=y,
            qr_width=width,
            qr_height=height,
            page_width=page_width,
            page_height=page_height
        )
        # Cerrar el documento PDF antes de eliminar el archivo temporal
        del analyzer
        return {"success": True, "data": result}

    except Exception as e:
        tb = traceback.format_exc()
        print(f"[ERROR] {tb}")
        return {"success": False, "error": str(e), "traceback": tb}
    finally:
        try:
            if tmp_path and os.path.exists(tmp_path):
                os.unlink(tmp_path)
        except PermissionError:
            pass  # Windows: archivo aún bloqueado, se limpiará después
