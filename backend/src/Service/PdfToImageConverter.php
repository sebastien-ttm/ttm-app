<?php

namespace App\Service;

use Psr\Log\LoggerInterface;

/**
 * Convertit la 1re page d'un PDF en PNG via l'extension Imagick
 * (qui s'appuie sur Ghostscript en interne).
 *
 * Utile pour permettre l'affichage inline d'un QR code distribué en PDF.
 * Échoue gracieusement si Imagick (ou Ghostscript) n'est pas dispo sur
 * l'hébergement : dans ce cas l'appelant peut conserver le PDF tel quel.
 */
class PdfToImageConverter
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public static function isAvailable(): bool
    {
        return extension_loaded('imagick') && class_exists(\Imagick::class);
    }

    /**
     * Convertit la 1re page de $pdfPath en PNG, écrit dans $outputPath.
     *
     * @param int $dpi Résolution de rendu (200 = lisible, 300 = meilleur, plus lourd)
     * @return bool true si conversion réussie
     */
    public function convert(string $pdfPath, string $outputPath, int $dpi = 200): bool
    {
        if (!self::isAvailable()) {
            $this->logger->info('PDF→PNG : Imagick non disponible, conversion ignorée.');
            return false;
        }
        if (!is_file($pdfPath) || !is_readable($pdfPath)) {
            return false;
        }

        try {
            $imagick = new \Imagick();
            $imagick->setResolution($dpi, $dpi);
            // [0] = 1re page uniquement (un PDF peut en contenir plusieurs)
            $imagick->readImage($pdfPath.'[0]');
            $imagick->setImageBackgroundColor('white');
            // Aplatit l'éventuelle transparence sur fond blanc (utile pour QR)
            $imagick = $imagick->flattenImages();
            $imagick->setImageFormat('png');
            $imagick->setImageCompressionQuality(90);

            $ok = $imagick->writeImage($outputPath);
            $imagick->clear();
            $imagick->destroy();

            return (bool) $ok;
        } catch (\Throwable $e) {
            $this->logger->warning('PDF→PNG : échec de la conversion', [
                'pdf' => $pdfPath,
                'exception' => $e->getMessage(),
            ]);
            return false;
        }
    }
}
