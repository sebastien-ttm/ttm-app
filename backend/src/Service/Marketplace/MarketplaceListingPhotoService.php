<?php

namespace App\Service\Marketplace;

use App\Entity\MarketplaceListing;
use App\Entity\MarketplaceListingPhoto;
use App\Service\ImageResizer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Gère le stockage des photos d'annonces de la bourse aux équipements :
 *  - upload dans public/uploads/marketplace/{listingId}/{hash}.jpg
 *    (URL publique — mêmes photos que celles montrées dans l'app,
 *    aucune raison de les protéger derrière l'auth)
 *  - réduction systématique via ImageResizer::compressToJpeg : plus
 *    grand côté 1280px, JPEG qualité 78, orientation EXIF appliquée.
 *    Sans crop — contrairement à l'avatar, on garde le cadrage choisi
 *    par le vendeur. Seule la version réduite est conservée.
 *
 * Un dossier par annonce (mêmes principes que AttachmentService pour
 * les pièces jointes de créneau) plutôt qu'un dossier plat — plus
 * simple à nettoyer intégralement quand l'annonce est supprimée.
 */
class MarketplaceListingPhotoService
{
    private const ALLOWED_MIME = ['image/jpeg', 'image/png', 'image/webp'];
    private const MAX_BYTES = 8_000_000;
    /** Plus grand côté après réduction (px) et qualité JPEG — ~150-350 Ko par photo. */
    private const MAX_SIDE = 1280;
    private const JPEG_QUALITY = 78;

    /** Nombre max de photos par annonce — appliqué côté MarketplaceController. */
    public const MAX_PHOTOS = 5;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ImageResizer $resizer,
        private readonly string $listingsDir,
        private readonly string $publicUrl,
    ) {
    }

    /**
     * Ajoute une photo à l'annonce (ne flush pas — l'appelant décide).
     *
     * @throws \RuntimeException si le fichier est rejeté
     */
    public function add(MarketplaceListing $listing, UploadedFile $file, int $position): MarketplaceListingPhoto
    {
        if ($listing->getId() === null) {
            throw new \RuntimeException('L\'annonce doit être enregistrée avant d\'y ajouter des photos.');
        }
        if ($file->getSize() > self::MAX_BYTES) {
            throw new \RuntimeException('Photo trop volumineuse (max 8 Mo).');
        }
        $mime = $file->getMimeType();
        if (!in_array($mime, self::ALLOWED_MIME, true)) {
            throw new \RuntimeException('Format non accepté. Formats autorisés : JPG, PNG, WebP.');
        }

        $dir = rtrim($this->listingsDir, '/\\').\DIRECTORY_SEPARATOR.$listing->getId();
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Impossible de créer le dossier photos.');
        }

        // Le fichier reçu (potentiellement plusieurs Mo) est déposé sous un
        // nom temporaire, réduit dans un .jpg définitif, puis supprimé :
        // seule la version réduite reste sur le disque.
        $base = bin2hex(random_bytes(8));
        $tmpName = $base.'.upload';
        $filename = $base.'.jpg';

        try {
            $file->move($dir, $tmpName);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Échec du déplacement : '.$e->getMessage(), 0, $e);
        }

        $tmpPath = $dir.\DIRECTORY_SEPARATOR.$tmpName;
        $ok = $this->resizer->compressToJpeg(
            $tmpPath,
            $dir.\DIRECTORY_SEPARATOR.$filename,
            $mime,
            self::MAX_SIDE,
            self::JPEG_QUALITY,
        );
        @unlink($tmpPath);
        if (!$ok) {
            @unlink($dir.\DIRECTORY_SEPARATOR.$filename);
            throw new \RuntimeException('Impossible de traiter cette photo (fichier illisible ou trop grand).');
        }

        $photo = new MarketplaceListingPhoto($listing, $filename, $position);
        $this->em->persist($photo);

        return $photo;
    }

    /** Supprime une photo (fichier + entité). Ne flush pas. */
    public function remove(MarketplaceListingPhoto $photo): void
    {
        $path = rtrim($this->listingsDir, '/\\').\DIRECTORY_SEPARATOR.$photo->getListing()->getId().\DIRECTORY_SEPARATOR.$photo->getStoredName();
        if (is_file($path)) {
            @unlink($path);
        }
        $this->em->remove($photo);
    }

    /**
     * Supprime le dossier entier d'une annonce (appelé quand l'annonce
     * elle-même est supprimée — la cascade Doctrine gère déjà les lignes
     * en base, ceci nettoie juste le disque).
     */
    public function removeAllFiles(MarketplaceListing $listing): void
    {
        $dir = rtrim($this->listingsDir, '/\\').\DIRECTORY_SEPARATOR.$listing->getId();
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $f) {
            if ($f === '.' || $f === '..') continue;
            @unlink($dir.\DIRECTORY_SEPARATOR.$f);
        }
        @rmdir($dir);
    }

    public function urlFor(MarketplaceListingPhoto $photo): string
    {
        return rtrim($this->publicUrl, '/').'/uploads/marketplace/'.$photo->getListing()->getId().'/'.$photo->getStoredName();
    }
}
