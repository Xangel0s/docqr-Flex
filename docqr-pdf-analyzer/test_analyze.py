"""Test script para verificar análisis de PDF con PyMuPDF"""
import sys
sys.path.insert(0, '.')
from app.analyzer import PDFAnalyzer

pdf = '../docqr-api/storage/app/uploads/IN/202603/Ig16DA2xOP67l9wanhsSqcGcQIkWVMSF/1-INF.-N-004-26-AG18-P.E.-FINO-V09.pdf'

print("=== ANALIZANDO PDF ===")
analyzer = PDFAnalyzer(pdf)
result = analyzer.analyze_page(1)

print(f"\n--- DIMENSIONES ---")
print(f"Pagina: {result['page_width_pt']:.1f}x{result['page_height_pt']:.1f} pt")
print(f"Pagina: {result['page_width_mm']:.1f}x{result['page_height_mm']:.1f} mm")
print(f"Contenido hasta: {result['content_bottom_pt']:.1f} pt ({result['content_bottom_mm']:.1f} mm)")

print(f"\n--- TEXTOS CRITICOS ({len(result['critical_texts'])}) ---")
for ct in result['critical_texts']:
    print(f"  '{ct['text']}' -> y0={ct['bbox_mm']['y0']:.1f}mm y1={ct['bbox_mm']['y1']:.1f}mm")

print(f"\n--- IMAGENES ({len(result['images'])}) ---")
for img in result['images']:
    print(f"  {img['width']:.0f}x{img['height']:.0f}pt en y0={img['bbox_mm']['y0']:.1f}mm y1={img['bbox_mm']['y1']:.1f}mm")

print(f"\n--- ULTIMOS 5 BLOQUES DE TEXTO ---")
for tb in result['text_blocks'][-5:]:
    print(f"  '{tb['text'][:60]}' -> y1={tb['bbox_mm']['y1']:.1f}mm")

# Test find_safe_position con posición problemática (pie de página)
print(f"\n\n=== TEST FIND_SAFE_POSITION ===")
print(f"Posición solicitada: x=165.89mm y=261.10mm w=26.46mm h=26.46mm (zona pie)")
safe = analyzer.find_safe_position(
    page_number=1,
    qr_x=165.89,
    qr_y=261.10,
    qr_width=26.46,
    qr_height=26.46,
    page_width=210.0,
    page_height=297.0
)

print(f"\n--- RESULTADO ---")
print(f"Ajustado: {safe['adjusted']}")
print(f"Posición final: x={safe['x']:.2f}mm y={safe['y']:.2f}mm")
print(f"Tamaño final: {safe['width']:.2f}x{safe['height']:.2f}mm")
print(f"Colisiones: {safe['collisions_count']}")
print(f"Colisiones restantes: {safe['remaining_collisions']}")
print(f"Razon: {safe['reason']}")
if safe.get('size_reduced'):
    print(f"Reducido de {safe.get('original_size_mm', '?'):.1f}mm a {safe.get('final_size_mm', '?'):.1f}mm")
