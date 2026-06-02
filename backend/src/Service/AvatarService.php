<?php

namespace App\Service;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Gère le stockage des avatars utilisateurs :
 *  - upload dans public/uploads/avatars/{hash}.{ext} (URL publique)
 *  - crop centré + redimensionnement carré (400×400) via ImageResizer
 *  - URL retournée pour usage dans les serializers
 *
 * Le fichier est public — pas d'auth pour le télécharger. Acceptable
 * pour un avatar (info volontairement publique au sein du club).
 */
class AvatarService
{
    private const ALLOWED_MIME = ['image/jpeg', 'image/png', 'image/webp'];
    private const MAX_BYTES = 5_000_000;
    private const AVATAR_SIZE = 400;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ImageResizer $resizer,
        private readonly string $avatarsDir,
        private readonly string $publicUrl,
    ) {
    }

    /**
     * Remplace l'avatar du user par le fichier uploadé.
     * Retourne le nom de fichier final stocké.
     *
     * @throws \RuntimeException si le fichier est rejeté
     */
    public function upload(User $user, UploadedFile $file): string
    {
        if ($file->getSize() > self::MAX_BYTES) {
            throw new \RuntimeException('Fichier trop volumineux (max 5 Mo).');
        }
        $mime = $file->getMimeType();
        if (!in_array($mime, self::ALLOWED_MIME, true)) {
            throw new \RuntimeException('Format non accepté. Formats autorisés : JPG, PNG, WebP.');
        }

        if (!is_dir($this->avatarsDir) && !@mkdir($this->avatarsDir, 0775, true) && !is_dir($this->avatarsDir)) {
            throw new \RuntimeException('Impossible de créer le dossier avatars.');
        }

        $ext = $file->guessExtension() ?: $file->getClientOriginalExtension() ?: 'jpg';
        $filename = sprintf('%d-%s.%s', $user->getId(), bin2hex(random_bytes(6)), strtolower($ext));

        try {
            $file->move($this->avatarsDir, $filename);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Échec du déplacement : '.$e->getMessage(), 0, $e);
        }

        $absolutePath = $this->avatarsDir.\DIRECTORY_SEPARATOR.$filename;
        $this->resizer->cropAndResizeToSquare($absolutePath, $mime, self::AVATAR_SIZE);

        // Supprime l'ancien avatar (s'il y en avait un)
        $this->removeFileForUser($user);

        $user->setAvatarFilename($filename);
        $this->em->flush();

        return $filename;
    }

    /** Supprime l'avatar (entité + fichier disque). */
    public function remove(User $user): void
    {
        $this->removeFileForUser($user);
        $user->setAvatarFilename(null);
        $this->em->flush();
    }

    /** URL publique absolue de l'avatar, ou null si pas d'avatar. */
    public function urlFor(User $user): ?string
    {
        $f = $user->getAvatarFilename();
        if ($f === null) {
            return null;
        }
        return rtrim($this->publicUrl, '/').'/uploads/avatars/'.$f;
    }

    private function removeFileForUser(User $user): void
    {
        $old = $user->getAvatarFilename();
        if ($old === null) return;
        $path = $this->avatarsDir.\DIRECTORY_SEPARATOR.$old;
        if (is_file($path)) {
            @unlink($path);
        }
    }
}
