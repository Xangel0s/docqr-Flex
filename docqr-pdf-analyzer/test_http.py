"""Test HTTP endpoint directly to see error details"""
import requests

pdf_path = r'C:\Users\Lenovo\Documents\GEOFALQR\docqr-api\storage\app\uploads\IN\202603\L9TyNM9yQ5ZFXV8WqmAcJJyaF3y1I2n8\1-INF.-N-004-26-AG18-P.E.-FINO-V09.pdf'

with open(pdf_path, 'rb') as f:
    response = requests.post(
        'http://127.0.0.1:8001/find-safe-position',
        files={'file': ('test.pdf', f, 'application/pdf')},
        data={
            'x': '165.89',
            'y': '261.10',
            'width': '26.46',
            'height': '26.46',
            'page_number': '1',
            'page_width': '210.0',
            'page_height': '297.0'
        }
    )

print(f"Status: {response.status_code}")
print(f"Response: {response.text[:500]}")
