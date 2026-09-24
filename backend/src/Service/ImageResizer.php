<?php

namespace App\Service;

use Psr\Log\LoggerInterface;

/**
 * Resizes uploaded images in place using PHP's GD extension.
 *
 * Used for:
 *  - Inline images uploaded via the rich editor (Trix attachments)
 *  - Article gallery photos uploaded via Vich
 *
 * Files larger than $maxWidth are downsized while preserving aspect ratio
 * and transparency. JPEG quality is recompressed at 85.
 */
class ImageResizer
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    /**
     * Resize the file at $path in place if its width exceeds $maxWidth.
     * No-op for unsupported MIME types or unreadable files.
     *
     * @return bool true if the file was actually resized, false otherwise
     */
    public function resizeInPlace(string $path, ?string $mime = null, int $maxWidth = 1200): bool
    {
        if (!is_file($path) || !is_readable($path)) {
            return false;
        }

        $info = @getimagesize($path);
        if ($info === false) {
            return false;
        }
        [$width, $height] = $info;
        $mime = $mime ?? ($info['mime'] ?? '');

        if ($width <= $maxWidth) {
            return false; // already small enough
        }

        $newWidth = $maxWidth;
        $newHeight = (int) round($height * ($maxWidth / $width));

        $src = $this->loadImage($path, $mime);
        if ($src === null) {
            return false;
        }

        $dst = imagecreatetruecolor($newWidth, $newHeight);
        if ($dst === false) {
            imagedestroy($src);
            return false;
        }

        if ($mime === 'image/png' || $mime === 'image/webp' || $mime === 'image/gif') {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
            if ($transparent !== false) {
                imagefilledrectangle($dst, 0, 0, $newWidth, $newHeight, $transparent);
            }
        }

        imagecopyresampled($dst, $src, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        $ok = $this->saveImage($dst, $path, $mime);

        imagedestroy($src);
        imagedestroy($dst);

        if (!$ok) {
            $this->logger->warning('Image resize: save failed', ['path' => $path, 'mime' => $mime]);
            return false;
        }

        return true;
    }

    /**
     * Crop centré + redimensionnement en carré pour un avatar.
     *
     * Si l'image n'est pas carrée, on prend le plus grand carré centré
     * (tronque les bords longs), puis on redimensionne à $size x $size.
     * Le fichier est écrit en place (même path, même MIME).
     *
     * @return bool true si l'opération a réussi
     */
    public function cropAndResizeToSquare(string $path, ?string $mime = null, int $size = 400): bool
    {
        if (!is_file($path) || !is_readable($path)) {
            return false;
        }

        $info = @getimagesize($path);
        if ($info === false) {
            return false;
        }
        [$width, $height] = $info;
        $mime = $mime ?? ($info['mime'] ?? '');

        $src = $this->loadImage($path, $mime);
        if ($src === null) {
            return false;
        }

        // Carré centré : on prend le plus petit côté
        $cropSize = min($width, $height);
        $cropX = (int) (($width - $cropSize) / 2);
        $cropY = (int) (($height - $cropSize) / 2);

        $dst = imagecreatetruecolor($size, $size);
        if ($dst === false) {
            imagedestroy($src);
            return false;
        }

        if ($mime === 'image/png' || $mime === 'image/webp' || $mime === 'image/gif') {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
            if ($transparent !== false) {
                imagefilledrectangle($dst, 0, 0, $size, $size, $transparent);
            }
        }

        imagecopyresampled($dst, $src, 0, 0, $cropX, $cropY, $size, $size, $cropSize, $cropSize);

        $ok = $this->saveImage($dst, $path, $mime);

        imagedestroy($src);
        imagedestroy($dst);

        if (!$ok) {
            $this->logger->warning('Avatar crop+resize: save failed', ['path' => $path, 'mime' => $mime]);
            return false;
        }
        return true;
    }

    /**
     * Réduit une photo pour l'affichage écran et l'écrit en JPEG dans
     * $destPath (fichier différent de la source) :
     *  - plus grand côté ≤ $maxSide (jamais d'agrandissement) ;
     *  - orientation EXIF appliquée AVANT réencodage — GD ne la lit pas
     *    et le réencodage supprime la métadonnée, donc sans ça les
     *    photos de téléphone en portrait ressortiraient couchées ;
     *  - transparence aplatie sur fond blanc (PNG/WebP → JPEG).
     *
     * Renvoie false si l'image est illisible ou trop grande pour être
     * traitée sans risquer de dépasser memory_limit (GD décompresse tout
     * en mémoire : ~5 octets par pixel).
     */
    public function compressToJpeg(string $srcPath, string $destPath, ?string $mime = null, int $maxSide = 1280, int $quality = 78): bool
    {
        if (!is_file($srcPath) || !is_readable($srcPath)) {
            return false;
        }
        $info = @getimagesize($srcPath);
        if ($info === false) {
            return false;
        }
        [$width, $height] = $info;
        $mime = $mime ?? ($info['mime'] ?? '');
        if ($width * $height > 36_000_000) {
            return false;
        }

        $src = $this->loadImage($srcPath, $mime);
        if ($src === null) {
            return false;
        }

        if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
            $exif = @exif_read_data($srcPath);
            $angle = match ((int) ($exif['Orientation'] ?? 1)) {
                3 => 180,
                6 => -90,
                8 => 90,
                default => 0,
            };
            if ($angle !== 0) {
                $rotated = imagerotate($src, $angle, 0);
                if ($rotated !== false) {
                    imagedestroy($src);
                    $src = $rotated;
                    $width = imagesx($src);
                    $height = imagesy($src);
                }
            }
        }

        $scale = min(1.0, $maxSide / max($width, $height));
        $newWidth = max(1, (int) round($width * $scale));
        $newHeight = max(1, (int) round($height * $scale));

        $dst = imagecreatetruecolor($newWidth, $newHeight);
        if ($dst === false) {
            imagedestroy($src);
            return false;
        }
        $white = imagecolorallocate($dst, 255, 255, 255);
        if ($white !== false) {
            imagefill($dst, 0, 0, $white);
        }
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        $ok = imagejpeg($dst, $destPath, $quality);

        imagedestroy($src);
        imagedestroy($dst);

        if (!$ok) {
            $this->logger->warning('Image compress: save failed', ['dest' => $destPath]);
        }
        return $ok;
    }

    private function loadImage(string $path, string $mime): \GdImage|null
    {
        try {
            return match ($mime) {
                'image/jpeg' => imagecreatefromjpeg($path),
                'image/png' => imagecreatefrompng($path),
                'image/webp' => imagecreatefromwebp($path),
                'image/gif' => imagecreatefromgif($path),
                default => null,
            } ?: null;
        } catch (\Throwable $e) {
            $this->logger->warning('Image resize: load failed', ['mime' => $mime, 'exception' => $e]);
            return null;
        }
    }

    private function saveImage(\GdImage $img, string $path, string $mime): bool
    {
        return match ($mime) {
            'image/jpeg' => imagejpeg($img, $path, 85),
            'image/png' => imagepng($img, $path, 7),
            'image/webp' => imagewebp($img, $path, 85),
            'image/gif' => imagegif($img, $path),
            default => false,
        };
    }
}
