<?php

namespace Tests\Unit;

use App\Services\PdfSignatureService;
use PHPUnit\Framework\TestCase;

class PdfSignatureServiceTest extends TestCase
{
    public function test_detects_digitally_signed_pdf_markers(): void
    {
        $service = new PdfSignatureService();
        $filePath = $this->createTempPdf(
            '%PDF-1.7' . "\n"
            . '1 0 obj << /Type /Catalog /AcroForm << /SigFlags 3 >> >> endobj' . "\n"
            . '2 0 obj << /FT /Sig /Subtype /Widget /Type /Annot >> endobj' . "\n"
            . '3 0 obj << /Type /Sig /ByteRange [0 10 20 30] >> endobj' . "\n"
            . '%%EOF'
        );

        try {
            $result = $service->analyzePath($filePath, 'signed.pdf');

            $this->assertTrue($result['signed']);
            $this->assertContains('/ByteRange', $result['markers']);
            $this->assertContains('/FT /Sig', $result['markers']);
            $this->assertNotNull($result['message']);
        } finally {
            @unlink($filePath);
        }
    }

    public function test_ignores_regular_unsigned_pdf(): void
    {
        $service = new PdfSignatureService();
        $filePath = $this->createTempPdf(
            '%PDF-1.4' . "\n"
            . '1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj' . "\n"
            . '2 0 obj << /Type /Pages /Count 1 /Kids [3 0 R] >> endobj' . "\n"
            . '3 0 obj << /Type /Page /Parent 2 0 R >> endobj' . "\n"
            . '%%EOF'
        );

        try {
            $result = $service->analyzePath($filePath, 'plain.pdf');

            $this->assertFalse($result['signed']);
            $this->assertSame([], $result['markers']);
            $this->assertNull($result['message']);
        } finally {
            @unlink($filePath);
        }
    }

    private function createTempPdf(string $content): string
    {
        $filePath = tempnam(sys_get_temp_dir(), 'pdfsig_');
        file_put_contents($filePath, $content);

        return $filePath;
    }
}
