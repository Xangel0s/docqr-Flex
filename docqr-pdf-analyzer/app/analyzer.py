"""
PDFAnalyzer - Motor de análisis inteligente de PDFs usando PyMuPDF
Detecta texto, imágenes, gráficos con coordenadas exactas (bounding boxes)
y calcula zonas seguras para inyección de QR.
"""

import fitz  # PyMuPDF
import logging
from typing import List, Dict, Any, Optional, Tuple

logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)

# Factor de conversión: 1 punto = 0.352778 mm
PT_TO_MM = 0.352778
MM_TO_PT = 2.83465


class PDFAnalyzer:
    """Analizador inteligente de PDFs para detección de obstáculos y zonas seguras"""

    def __init__(self, pdf_path: str):
        self.pdf_path = pdf_path
        self.doc = fitz.open(pdf_path)

    def __del__(self):
        if hasattr(self, 'doc') and self.doc:
            self.doc.close()

    def analyze_page(self, page_number: int = 1) -> Dict[str, Any]:
        """
        Analizar una página completa del PDF.
        Devuelve todos los elementos detectados con coordenadas exactas.
        
        Coordenadas en sistema PDF estándar (puntos, origen top-left).
        """
        if page_number < 1 or page_number > len(self.doc):
            raise ValueError(f"Página {page_number} no existe. El PDF tiene {len(self.doc)} páginas.")

        page = self.doc[page_number - 1]
        page_rect = page.rect

        result = {
            "page_number": page_number,
            "page_width_pt": page_rect.width,
            "page_height_pt": page_rect.height,
            "page_width_mm": page_rect.width * PT_TO_MM,
            "page_height_mm": page_rect.height * PT_TO_MM,
            "text_blocks": self._extract_text_blocks(page),
            "images": self._extract_images(page),
            "drawings": self._extract_drawings(page),
            "critical_texts": self._find_critical_texts(page),
            "content_bottom_pt": 0,
            "content_bottom_mm": 0
        }

        # Calcular el punto más bajo del contenido
        all_bottoms = []
        for block in result["text_blocks"]:
            all_bottoms.append(block["bbox"]["y1"])
        for img in result["images"]:
            all_bottoms.append(img["bbox"]["y1"])
        for drawing in result["drawings"]:
            all_bottoms.append(drawing["bbox"]["y1"])

        if all_bottoms:
            result["content_bottom_pt"] = max(all_bottoms)
            result["content_bottom_mm"] = max(all_bottoms) * PT_TO_MM

        return result

    def _extract_text_blocks(self, page: fitz.Page) -> List[Dict]:
        """Extraer bloques de texto con coordenadas exactas"""
        blocks = []
        text_dict = page.get_text("dict", flags=fitz.TEXT_PRESERVE_WHITESPACE)

        for block in text_dict.get("blocks", []):
            if block.get("type") == 0:  # Texto
                bbox = block["bbox"]
                text_content = ""
                font_size = 0

                for line in block.get("lines", []):
                    for span in line.get("spans", []):
                        text_content += span.get("text", "")
                        font_size = max(font_size, span.get("size", 0))

                text_content = text_content.strip()
                if text_content:
                    blocks.append({
                        "text": text_content,
                        "bbox": {
                            "x0": bbox[0],
                            "y0": bbox[1],
                            "x1": bbox[2],
                            "y1": bbox[3]
                        },
                        "bbox_mm": {
                            "x0": bbox[0] * PT_TO_MM,
                            "y0": bbox[1] * PT_TO_MM,
                            "x1": bbox[2] * PT_TO_MM,
                            "y1": bbox[3] * PT_TO_MM
                        },
                        "font_size": font_size,
                        "width": bbox[2] - bbox[0],
                        "height": bbox[3] - bbox[1]
                    })

        return blocks

    def _extract_images(self, page: fitz.Page) -> List[Dict]:
        """
        Extraer imágenes con coordenadas exactas (firmas, sellos, logos).
        IGNORA imágenes de fondo que cubren >70% de la página (plantillas escaneadas).
        """
        images = []
        image_list = page.get_image_info(xrefs=True)
        page_area = page.rect.width * page.rect.height

        for img in image_list:
            bbox = img.get("bbox", (0, 0, 0, 0))
            img_width = bbox[2] - bbox[0]
            img_height = bbox[3] - bbox[1]
            img_area = img_width * img_height

            # Ignorar imágenes muy pequeñas (<5pt)
            if img_width < 5 or img_height < 5:
                continue

            # CRÍTICO: Ignorar imágenes de fondo (>70% del área de la página)
            # Estas son plantillas escaneadas, no obstáculos reales
            if img_area > page_area * 0.7:
                logger.info(f"Ignorando imagen de fondo: {img_width:.0f}x{img_height:.0f}pt "
                           f"({img_area/page_area*100:.0f}% del área de página)")
                continue

            images.append({
                "bbox": {
                    "x0": bbox[0],
                    "y0": bbox[1],
                    "x1": bbox[2],
                    "y1": bbox[3]
                },
                "bbox_mm": {
                    "x0": bbox[0] * PT_TO_MM,
                    "y0": bbox[1] * PT_TO_MM,
                    "x1": bbox[2] * PT_TO_MM,
                    "y1": bbox[3] * PT_TO_MM
                },
                "width": img_width,
                "height": img_height,
                "area": img_area,
                "xref": img.get("xref", 0)
            })

        return images

    def _extract_drawings(self, page: fitz.Page) -> List[Dict]:
        """Extraer gráficos vectoriales (líneas, rectángulos, etc.)"""
        drawings = []
        for drawing in page.get_drawings():
            rect = drawing.get("rect", fitz.Rect(0, 0, 0, 0))
            if rect.width > 10 and rect.height > 2:  # Ignorar líneas muy pequeñas
                drawings.append({
                    "bbox": {
                        "x0": rect.x0,
                        "y0": rect.y0,
                        "x1": rect.x1,
                        "y1": rect.y1
                    },
                    "bbox_mm": {
                        "x0": rect.x0 * PT_TO_MM,
                        "y0": rect.y0 * PT_TO_MM,
                        "x1": rect.x1 * PT_TO_MM,
                        "y1": rect.y1 * PT_TO_MM
                    },
                    "type": drawing.get("type", "unknown"),
                    "width": rect.width,
                    "height": rect.height
                })

        return drawings

    def _find_critical_texts(self, page: fitz.Page) -> List[Dict]:
        """Buscar textos críticos que NUNCA deben ser solapados"""
        critical_keywords = [
            "Fin del Informe", "Fin del informe", "FIN DEL INFORME",
            "Observaciones", "OBSERVACIONES",
            "Página", "Versión"
        ]

        critical = []
        text_dict = page.get_text("dict")

        for block in text_dict.get("blocks", []):
            if block.get("type") != 0:
                continue
            for line in block.get("lines", []):
                for span in line.get("spans", []):
                    text = span.get("text", "").strip()
                    for keyword in critical_keywords:
                        if keyword.lower() in text.lower():
                            bbox = span.get("bbox", line.get("bbox", block["bbox"]))
                            critical.append({
                                "text": text,
                                "keyword": keyword,
                                "bbox": {
                                    "x0": bbox[0],
                                    "y0": bbox[1],
                                    "x1": bbox[2],
                                    "y1": bbox[3]
                                },
                                "bbox_mm": {
                                    "x0": bbox[0] * PT_TO_MM,
                                    "y0": bbox[1] * PT_TO_MM,
                                    "x1": bbox[2] * PT_TO_MM,
                                    "y1": bbox[3] * PT_TO_MM
                                }
                            })

        return critical

    def find_safe_position(
        self,
        page_number: int,
        qr_x: float,
        qr_y: float,
        qr_width: float,
        qr_height: float,
        page_width: float,
        page_height: float
    ) -> Dict[str, Any]:
        """
        Encontrar posición segura para QR respetando la región elegida por el usuario.
        
        Parámetros (en mm, sistema TCPDF top-left):
            qr_x, qr_y: Posición original del QR elegida por el usuario
            qr_width, qr_height: Tamaño del QR
            page_width, page_height: Dimensiones de la página en mm
        
        Retorna:
            Posición y tamaño ajustados para evitar obstáculos
        """
        page = self.doc[page_number - 1]
        page_rect = page.rect

        # Convertir posición del QR de mm (TCPDF) a puntos (PDF)
        qr_x_pt = qr_x * MM_TO_PT
        qr_y_pt = qr_y * MM_TO_PT
        qr_w_pt = qr_width * MM_TO_PT
        qr_h_pt = qr_height * MM_TO_PT

        logger.info(f"=== ANÁLISIS INTELIGENTE PyMuPDF ===")
        logger.info(f"QR solicitado: x={qr_x:.1f}mm y={qr_y:.1f}mm w={qr_width:.1f}mm h={qr_height:.1f}mm")
        logger.info(f"Página: {page_rect.width:.1f}x{page_rect.height:.1f} pt ({page_width:.1f}x{page_height:.1f} mm)")

        # Obtener TODOS los obstáculos
        obstacles = self._get_all_obstacles(page)
        logger.info(f"Obstáculos detectados: {len(obstacles)}")

        # Determinar la región del usuario (superior/inferior/central)
        page_middle_mm = page_height / 2
        user_region = "footer" if qr_y > page_middle_mm else "header"
        logger.info(f"Región elegida por usuario: {user_region}")

        # Detectar colisiones con la posición original
        qr_rect_pt = fitz.Rect(qr_x_pt, qr_y_pt, qr_x_pt + qr_w_pt, qr_y_pt + qr_h_pt)
        collisions = self._find_collisions(qr_rect_pt, obstacles)

        if not collisions:
            logger.info("✅ Sin colisiones. Posición original es segura.")
            return {
                "x": qr_x,
                "y": qr_y,
                "width": qr_width,
                "height": qr_height,
                "adjusted": False,
                "reason": "Sin colisiones detectadas",
                "collisions_count": 0
            }

        logger.info(f"⚠️ {len(collisions)} colisiones detectadas. Buscando posición segura...")
        for col in collisions:
            logger.info(f"  - Colisión con: {col.get('type', 'unknown')} "
                       f"'{col.get('text', '')[:30]}' "
                       f"en ({col['bbox']['x0']:.0f},{col['bbox']['y0']:.0f})-"
                       f"({col['bbox']['x1']:.0f},{col['bbox']['y1']:.0f})")

        # Calcular posición segura DENTRO de la misma región
        safe_pos = self._calculate_safe_position(
            page=page,
            obstacles=obstacles,
            collisions=collisions,
            qr_x_pt=qr_x_pt,
            qr_y_pt=qr_y_pt,
            qr_w_pt=qr_w_pt,
            qr_h_pt=qr_h_pt,
            page_rect=page_rect,
            user_region=user_region
        )

        return safe_pos

    def _get_all_obstacles(self, page: fitz.Page) -> List[Dict]:
        """Obtener todos los obstáculos de la página con bounding boxes"""
        obstacles = []

        # Texto
        text_dict = page.get_text("dict")
        for block in text_dict.get("blocks", []):
            if block.get("type") == 0:
                bbox = block["bbox"]
                text = ""
                for line in block.get("lines", []):
                    for span in line.get("spans", []):
                        text += span.get("text", "")

                text = text.strip()
                if text:
                    obstacles.append({
                        "type": "text",
                        "text": text,
                        "bbox": {"x0": bbox[0], "y0": bbox[1], "x1": bbox[2], "y1": bbox[3]},
                        "rect": fitz.Rect(bbox)
                    })

        # Imágenes (excluyendo fondos de página completa)
        page_area = page.rect.width * page.rect.height
        for img in page.get_image_info(xrefs=True):
            bbox = img.get("bbox", (0, 0, 0, 0))
            img_w = bbox[2] - bbox[0]
            img_h = bbox[3] - bbox[1]
            img_area = img_w * img_h
            if img_w > 10 and img_h > 10 and img_area < page_area * 0.7:
                obstacles.append({
                    "type": "image",
                    "text": f"imagen {img.get('xref', '?')}",
                    "bbox": {"x0": bbox[0], "y0": bbox[1], "x1": bbox[2], "y1": bbox[3]},
                    "rect": fitz.Rect(bbox)
                })

        # Gráficos vectoriales significativos
        for drawing in page.get_drawings():
            rect = drawing.get("rect", fitz.Rect(0, 0, 0, 0))
            if rect.width > 20 and rect.height > 5:
                obstacles.append({
                    "type": "drawing",
                    "text": "gráfico vectorial",
                    "bbox": {"x0": rect.x0, "y0": rect.y0, "x1": rect.x1, "y1": rect.y1},
                    "rect": rect
                })

        return obstacles

    def _find_collisions(self, qr_rect: fitz.Rect, obstacles: List[Dict], margin: float = 5.0) -> List[Dict]:
        """Encontrar obstáculos que colisionan con el rectángulo del QR"""
        expanded = fitz.Rect(
            qr_rect.x0 - margin,
            qr_rect.y0 - margin,
            qr_rect.x1 + margin,
            qr_rect.y1 + margin
        )

        collisions = []
        for obs in obstacles:
            obs_rect = obs["rect"]
            if expanded.intersects(obs_rect):
                collisions.append(obs)

        return collisions

    def _calculate_safe_position(
        self,
        page: fitz.Page,
        obstacles: List[Dict],
        collisions: List[Dict],
        qr_x_pt: float,
        qr_y_pt: float,
        qr_w_pt: float,
        qr_h_pt: float,
        page_rect: fitz.Rect,
        user_region: str
    ) -> Dict[str, Any]:
        """
        Calcular posición segura para el QR dentro de la región elegida por el usuario.
        
        Estrategia:
        1. Encontrar el borde inferior de todos los obstáculos en la zona del QR
        2. Colocar el QR justo después de los obstáculos
        3. Reducir tamaño si es necesario
        4. Mantener en la misma región elegida por el usuario
        """
        margin_pt = 4  # 4pt ≈ 1.4mm de margen entre contenido y QR
        page_bottom_margin_pt = 8  # ~2.8mm margen inferior (pie de página)

        # Encontrar el punto más bajo de todos los obstáculos que colisionan
        max_obstacle_bottom = 0
        for col in collisions:
            bottom = col["bbox"]["y1"]
            if bottom > max_obstacle_bottom:
                max_obstacle_bottom = bottom

        logger.info(f"Obstáculo más bajo colisionando: {max_obstacle_bottom:.1f}pt "
                    f"({max_obstacle_bottom * PT_TO_MM:.1f}mm)")

        # Posición Y ideal: justo después de los obstáculos
        ideal_y_pt = max_obstacle_bottom + margin_pt
        available_height_pt = page_rect.height - ideal_y_pt - page_bottom_margin_pt

        logger.info(f"Posición Y ideal: {ideal_y_pt:.1f}pt ({ideal_y_pt * PT_TO_MM:.1f}mm)")
        logger.info(f"Espacio disponible: {available_height_pt:.1f}pt ({available_height_pt * PT_TO_MM:.1f}mm)")

        # Reducir tamaño del QR si no cabe
        final_w_pt = qr_w_pt
        final_h_pt = qr_h_pt
        size_reduced = False

        if qr_h_pt > available_height_pt:
            # Reducir proporcionalmente
            if available_height_pt > 20:  # ~7mm mínimo
                final_h_pt = available_height_pt * 0.85
                final_w_pt = final_h_pt  # Mantener cuadrado
                size_reduced = True
                logger.info(f"🔄 Reduciendo QR: {qr_h_pt:.1f}pt → {final_h_pt:.1f}pt "
                           f"({qr_h_pt * PT_TO_MM:.1f}mm → {final_h_pt * PT_TO_MM:.1f}mm)")
            elif available_height_pt > 15:  # ~5mm
                final_h_pt = available_height_pt * 0.9
                final_w_pt = final_h_pt
                size_reduced = True
                logger.info(f"🔄 Reducción agresiva: {qr_h_pt:.1f}pt → {final_h_pt:.1f}pt")
            else:
                # Espacio muy reducido - intentar con QR mínimo
                final_h_pt = max(14.0, available_height_pt * 0.95)  # ~5mm mínimo
                final_w_pt = final_h_pt
                size_reduced = True
                logger.info(f"🔄 Reducción máxima: {qr_h_pt:.1f}pt → {final_h_pt:.1f}pt")

        # Recalcular Y con el nuevo tamaño
        final_y_pt = ideal_y_pt

        # Verificar que no se sale de la página
        if final_y_pt + final_h_pt > page_rect.height - page_bottom_margin_pt:
            # Ajustar Y para que quepa
            final_y_pt = page_rect.height - final_h_pt - page_bottom_margin_pt
            if final_y_pt < max_obstacle_bottom:
                logger.warning("⚠️ No hay espacio suficiente ni reduciendo al máximo")

        # Verificar X: buscar posición lateral que no colisione
        final_x_pt = qr_x_pt
        qr_candidate = fitz.Rect(final_x_pt, final_y_pt, final_x_pt + final_w_pt, final_y_pt + final_h_pt)
        new_collisions = self._find_collisions(qr_candidate, obstacles, margin=3.0)

        if new_collisions:
            # Intentar desplazamientos laterales
            offsets = [0, 20, -20, 40, -40, 60, -60]
            for offset in offsets:
                test_x = qr_x_pt + offset
                if test_x < 10 or test_x + final_w_pt > page_rect.width - 10:
                    continue

                test_rect = fitz.Rect(test_x, final_y_pt, test_x + final_w_pt, final_y_pt + final_h_pt)
                test_collisions = self._find_collisions(test_rect, obstacles, margin=3.0)

                if not test_collisions:
                    final_x_pt = test_x
                    logger.info(f"✅ Desplazamiento lateral de {offset:.0f}pt resuelve colisión")
                    break
            else:
                logger.warning("⚠️ No se pudo resolver colisión con desplazamiento lateral")

        # Convertir resultado a mm para TCPDF
        result_x_mm = final_x_pt * PT_TO_MM
        result_y_mm = final_y_pt * PT_TO_MM
        result_w_mm = final_w_pt * PT_TO_MM
        result_h_mm = final_h_pt * PT_TO_MM

        logger.info(f"=== RESULTADO FINAL ===")
        logger.info(f"Posición: x={result_x_mm:.2f}mm y={result_y_mm:.2f}mm")
        logger.info(f"Tamaño: {result_w_mm:.2f}x{result_h_mm:.2f}mm")
        logger.info(f"Reducido: {size_reduced}")

        # Verificar colisiones finales
        final_rect = fitz.Rect(final_x_pt, final_y_pt, final_x_pt + final_w_pt, final_y_pt + final_h_pt)
        remaining_collisions = self._find_collisions(final_rect, obstacles, margin=2.0)

        return {
            "x": result_x_mm,
            "y": result_y_mm,
            "width": result_w_mm,
            "height": result_h_mm,
            "adjusted": True,
            "size_reduced": size_reduced,
            "original_size_mm": qr_h_pt * PT_TO_MM,
            "final_size_mm": result_h_mm,
            "reason": f"{len(collisions)} colisiones detectadas, posición ajustada",
            "collisions_count": len(collisions),
            "remaining_collisions": len(remaining_collisions),
            "obstacles_detected": len(obstacles),
            "content_bottom_pt": max_obstacle_bottom,
            "content_bottom_mm": max_obstacle_bottom * PT_TO_MM,
            "available_space_mm": available_height_pt * PT_TO_MM
        }
