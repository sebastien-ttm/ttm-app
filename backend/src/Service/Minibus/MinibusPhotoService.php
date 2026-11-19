<?php

namespace App\Service\Minibus;

use App\Entity\MinibusBooking;
use App\Entity\MinibusCheck;
use App\Entity\MinibusCheckPhoto;
use App\Service\ImageResizer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * Photos des états des lieux du minibus : stockées hors de la partie publique du site, dans
 * var/uploads/minibus/{emprunt}/{hash}.jpg, et servies uniquement par l'API (ou le back-office) authentifiée.
 * Chaque photo reçue est réduite (plus grand côté 1600 px, JPEG qualité 80, orientation EXIF appliquée) : on y distingue
 * encore une rayure, et elle pèse quelques centaines de Ko.
 */
class MinibusPhotoService
{
    /** Nombre maximal de photos par état des lieux (le tour du minibus et de la remorque tient largement dedans). */
    public const MAX_PHOTOS_PER_CHECK = 12;

    private const ALLOWED_MIME = ['image/jpeg', 'image/png', 'image/webp'];
    private const MAX_BYTES = 12_000_000;
    private const MAX_SIDE = 1600;
    private const JPEG_QUALITY = 80;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ImageResizer $resizer,
        private readonly string $minibusDir,
    ) {
    }

    /**
     * Ajoute une photo à l'état des lieux (ne flush pas — l'appelant décide). Le fichier reçu est réduit dans un .jpg
     * définitif, seule la version réduite reste sur le disque.
     *
     * @throws MinibusException si la photo est refusée
     */
    public function add(MinibusCheck $check, UploadedFile $file, int $position): MinibusCheckPhoto
    {
        $booking = $check->getBooking();
        if ($booking->getId() === null) {
            throw MinibusException::conflict('L\'emprunt doit être enregistré avant d\'y ajouter des photos.');
        }
        if (!$file->isValid()) {
            throw MinibusException::invalid('Une photo n\'a pas pu être reçue (trop volumineuse ?). Réessayez.');
        }
        if ((int) $file->getSize() > self::MAX_BYTES) {
            throw MinibusException::invalid('Photo trop volumineuse (12 Mo au plus).');
        }
        $mime = (string) $file->getMimeType();
        if (!in_array($mime, self::ALLOWED_MIME, true)) {
            throw MinibusException::invalid('Format non accepté : envoyez des photos JPG, PNG ou WebP.');
        }

        $dir = $this->bookingDir($booking);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw MinibusException::conflict('Impossible de créer le dossier de stockage des photos.');
        }

        $base = bin2hex(random_bytes(10));
        $tmpName = $base.'.upload';
        $filename = $base.'.jpg';
        try {
            $file->move($dir, $tmpName);
        } catch (\Throwable $e) {
            throw MinibusException::conflict('L\'enregistrement de la photo a échoué.');
        }
        $tmpPath = $dir.\DIRECTORY_SEPARATOR.$tmpName;
        $ok = $this->resizer->compressToJpeg($tmpPath, $dir.\DIRECTORY_SEPARATOR.$filename, $mime, self::MAX_SIDE, self::JPEG_QUALITY);
        @unlink($tmpPath);
        if (!$ok) {
            @unlink($dir.\DIRECTORY_SEPARATOR.$filename);
            throw MinibusException::invalid('Impossible de traiter une des photos (fichier illisible ou trop grand).');
        }

        $photo = new MinibusCheckPhoto($check, $filename, $position);
        $check->addPhoto($photo);
        $this->em->persist($photo);

        return $photo;
    }

    /** Supprime le fichier d'une photo (sans toucher à la base). */
    public function deleteFile(MinibusCheckPhoto $photo): void
    {
        $path = $this->pathOf($photo);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /** Supprime la photo : fichier et ligne (le flush reste à l'appelant). */
    public function remove(MinibusCheckPhoto $photo): void
    {
        $this->deleteFile($photo);
        $photo->getCheck()->removePhoto($photo);
        $this->em->remove($photo);
    }

    /** Supprime tout le dossier d'un emprunt (à sa suppression : la cascade Doctrine ne gère que les lignes). */
    public function removeAllFiles(MinibusBooking $booking): void
    {
        if ($booking->getId() === null) {
            return;
        }
        $dir = $this->bookingDir($booking);
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $name) {
            if ($name !== '.' && $name !== '..') {
                @unlink($dir.\DIRECTORY_SEPARATOR.$name);
            }
        }
        @rmdir($dir);
    }

    /** Réponse fichier : la photo s'affiche dans l'application ou le navigateur. null si le fichier a disparu. */
    public function response(MinibusCheckPhoto $photo): ?BinaryFileResponse
    {
        $path = $this->pathOf($photo);
        if (!is_file($path)) {
            return null;
        }
        $response = new BinaryFileResponse($path);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, 'minibus-'.$photo->getId().'.jpg');
        $response->headers->set('Content-Type', 'image/jpeg');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        // Une photo ne change jamais (son identifiant ne désigne qu'un fichier) : le navigateur peut la garder, sans la partager.
        $response->headers->set('Cache-Control', 'private, max-age=86400');

        return $response;
    }

    private function bookingDir(MinibusBooking $booking): string
    {
        return rtrim($this->minibusDir, '/\\').\DIRECTORY_SEPARATOR.(string) $booking->getId();
    }

    /** Chemin du fichier : le nom est ramené à sa dernière composante, jamais de sortie du dossier. */
    private function pathOf(MinibusCheckPhoto $photo): string
    {
        return $this->bookingDir($photo->getCheck()->getBooking()).\DIRECTORY_SEPARATOR.basename($photo->getStoredName());
    }
}
