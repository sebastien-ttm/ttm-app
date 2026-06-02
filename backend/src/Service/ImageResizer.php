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
