<?php

namespace App\Services;

use Endroid\QrCode\QrCode;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Writer\PngWriter;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;

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
     * @param int $size Tamaño del QR (píxeles) - tamaño total incluyendo margen
     * @return string Ruta relativa del QR guardado
     */
    public function generateWithSize(string $url, string $filename, int $size = 300): string
    {
        // Para tamaños grandes (1024px), usar margen de 0 para obtener exactamente el tamaño solicitado
        // Para tamaños pequeños (300px), mantener margen de 10 para mejor legibilidad
        $margin = ($size >= 1024) ? 0 : 10;
        
        // Si hay margen, calcular el tamaño del QR interno para que el total sea exactamente $size
        // El margen se agrega en ambos lados, así que: tamaño_total = tamaño_qr + (margin * 2)
        $qrSize = ($margin > 0) ? ($size - ($margin * 2)) : $size;
        
        // Asegurar que el tamaño del QR sea al menos 1 píxel
        if ($qrSize < 1) {
            $qrSize = 1;
            $margin = 0;
        }

        // Crear el QR usando la nueva API de endroid/qr-code 5.x
        $qrCode = QrCode::create($url)
            ->setEncoding(new Encoding('UTF-8'))
            ->setErrorCorrectionLevel(ErrorCorrectionLevel::Low)
            ->setSize($qrSize)
            ->setMargin($margin);

        // Escribir el QR usando PngWriter
        $writer = new PngWriter();
        $result = $writer->write($qrCode);

        $qrPath = 'qrcodes/' . $filename . '.png';
        $qrContent = $result->getString();
        
        // Si el tamaño solicitado es 1024, redimensionar a exactamente 1024x1024
        // Esto garantiza que el tamaño final sea exactamente el solicitado
        if ($size === 1024) {
            try {
                $manager = new ImageManager(new Driver());
                $image = $manager->read($qrContent);
                
                // Redimensionar a exactamente 1024x1024 (forzar tamaño exacto)
                $image->scale(width: 1024, height: 1024);
                
                // Obtener el contenido redimensionado
                $qrContent = $image->toPng();
            } catch (\Exception $e) {
                // Si falla el redimensionamiento, usar el QR original
                // Log del error pero continuar
                \Log::warning('Error al redimensionar QR a 1024x1024: ' . $e->getMessage());
            }
        }
        
        Storage::disk('qrcodes')->put($filename . '.png', $qrContent);

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

