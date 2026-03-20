<?php

namespace App\Services;

/**
 * Detecta firmas digitales en PDFs para bloquear flujos que reescriben el archivo.
 *
 * Reescribir un PDF firmado con herramientas como pdf-lib o FPDI elimina o invalida
 * la firma existente, por lo que este servicio actúa como guardia preventiva.
 */
class PdfSignatureService
{
    /**
     * Patrones ASCII comunes presentes en PDFs firmados digitalmente.
     *
     * @var array<int, string>
     */
    private array $signatureMarkers = [
        '/ByteRange',
        '/FT /Sig',
        '/FT/Sig',
        '/Type /Sig',
        '/Type/Sig',
        '/AcroForm',
        '/SigFlags',
        '/Subtype /Widget',
    ];

    /**
     * Inspecciona un UploadedFile.
     *
     * @param mixed $file
     * @return array{signed: bool, markers: array<int, string>, message: ?string}
     */
    public function analyzeUploadedFile($file): array
    {
        $realPath = $file?->getRealPath();
        $filename = $file?->getClientOriginalName();

        if (!$realPath) {
            return [
                'signed' => false,
                'markers' => [],
                'message' => null,
            ];
        }

        return $this->analyzePath($realPath, $filename);
    }

    /**
     * Inspecciona un PDF existente en disco.
     *
     * @return array{signed: bool, markers: array<int, string>, message: ?string}
     */
    public function analyzePath(string $filePath, ?string $filename = null): array
    {
        if (!is_file($filePath) || !is_readable($filePath)) {
            return [
                'signed' => false,
                'markers' => [],
                'message' => null,
            ];
        }

        $markers = $this->scanMarkers($filePath);
        $signed = $this->isSigned($markers);

        return [
            'signed' => $signed,
            'markers' => array_keys(array_filter($markers)),
            'message' => $signed ? $this->buildBlockedMessage($filename) : null,
        ];
    }

    public function buildBlockedMessage(?string $filename = null): string
    {
        $documentLabel = $filename ? "El PDF \"{$filename}\"" : 'El PDF';

        return $documentLabel . ' tiene una firma digital. No se puede inyectar el QR ni generar una nueva version sin invalidar esa firma. Usa un PDF sin firmar o firma el documento despues de colocar el QR.';
    }

    /**
     * @return array<string, bool>
     */
    private function scanMarkers(string $filePath): array
    {
        $found = [];
        foreach ($this->signatureMarkers as $marker) {
            $found[$marker] = false;
        }

        $handle = fopen($filePath, 'rb');
        if ($handle === false) {
            return $found;
        }

        $chunkSize = 1024 * 1024;
        $overlapSize = max(array_map('strlen', $this->signatureMarkers)) - 1;
        $carry = '';

        try {
            while (!feof($handle)) {
                $chunk = fread($handle, $chunkSize);
                if ($chunk === false) {
                    break;
                }

                $buffer = $carry . $chunk;

                foreach ($this->signatureMarkers as $marker) {
                    if (!$found[$marker] && strpos($buffer, $marker) !== false) {
                        $found[$marker] = true;
                    }
                }

                if ($this->isSigned($found)) {
                    break;
                }

                $carry = strlen($buffer) > $overlapSize
                    ? substr($buffer, -$overlapSize)
                    : $buffer;
            }
        } finally {
            fclose($handle);
        }

        return $found;
    }

    /**
     * @param array<string, bool> $markers
     */
    private function isSigned(array $markers): bool
    {
        $hasByteRange = $markers['/ByteRange'] ?? false;
        $hasSignatureType = ($markers['/FT /Sig'] ?? false)
            || ($markers['/FT/Sig'] ?? false)
            || ($markers['/Type /Sig'] ?? false)
            || ($markers['/Type/Sig'] ?? false);
        $hasFormContext = ($markers['/AcroForm'] ?? false)
            || ($markers['/SigFlags'] ?? false)
            || ($markers['/Subtype /Widget'] ?? false);

        return ($hasByteRange && $hasSignatureType) || ($hasSignatureType && $hasFormContext);
    }
}
