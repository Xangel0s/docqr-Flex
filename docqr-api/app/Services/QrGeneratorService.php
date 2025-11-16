<?php

namespace App\Services;

use Endroid\QrCode\QrCode;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Writer\PngWriter;
use Illuminate\Support\Facades\Storage;

/**
 * Servicio para generar códigos QR
 * 
 * Genera códigos QR en formato PNG y los guarda en el storage
 */
class QrGeneratorService
{
    /**
     * Generar código QR y guardarlo en el storage
     * 
     * @param string $url URL o texto para el QR
     * @param string $filename Nombre del archivo (sin extensión)
     * @return string Ruta relativa del QR guardado (storage/qrcodes/...)
     */
    public function generate(string $url, string $filename): string
    {
        // Crear el QR usando la nueva API de endroid/qr-code 5.x
        $qrCode = QrCode::create($url)
            ->setEncoding(new Encoding('UTF-8'))
            ->setErrorCorrectionLevel(ErrorCorrectionLevel::Low)
            ->setSize(300)
            ->setMargin(10);

        // Escribir el QR usando PngWriter
        $writer = new PngWriter();
        $result = $writer->write($qrCode);

        // Ruta donde se guardará el QR
        $qrPath = 'qrcodes/' . $filename . '.png';
        
        // Guardar el QR en el storage
        Storage::disk('qrcodes')->put($filename . '.png', $result->getString());

        return $qrPath;
    }

    /**
     * Generar QR con tamaño personalizado
     * 
     * @param string $url URL o texto para el QR
     * @param string $filename Nombre del archivo
     * @param int $size Tamaño del QR (píxeles)
     * @return string Ruta relativa del QR guardado
     */
    public function generateWithSize(string $url, string $filename, int $size = 300): string
    {
        // Crear el QR usando la nueva API de endroid/qr-code 5.x
        $qrCode = QrCode::create($url)
            ->setEncoding(new Encoding('UTF-8'))
            ->setErrorCorrectionLevel(ErrorCorrectionLevel::Low)
            ->setSize($size)
            ->setMargin(10);

        // Escribir el QR usando PngWriter
        $writer = new PngWriter();
        $result = $writer->write($qrCode);

        $qrPath = 'qrcodes/' . $filename . '.png';
        Storage::disk('qrcodes')->put($filename . '.png', $result->getString());

        return $qrPath;
    }

    /**
     * Obtener la URL pública del QR
     * 
     * @param string $qrPath Ruta relativa del QR
     * @return string URL pública completa
     */
    public function getPublicUrl(string $qrPath): string
    {
        return Storage::disk('qrcodes')->url(basename($qrPath));
    }
}

