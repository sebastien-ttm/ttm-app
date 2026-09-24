<?php

namespace App\Service\Marketplace;

use App\Entity\MarketplaceListing;
use App\Entity\MarketplaceListingPhoto;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Gère le stockage des photos d'annonces de la bourse aux équipements :
 *  - upload dans public/uploads/marketplace/{listingId}/{hash}.{ext}
 *    (URL publique — mêmes photos que celles montrées dans l'app,
 *    aucune raison de les protéger derrière l'auth)
 *  - redimensionnement (largeur max 1600px) via ImageResizer, sans
 *    crop — contrairement à l'avatar, on veut garder le cadrage choisi
 *    par le vendeur.
 *
 * Un dossier par annonce (mêmes principes que AttachmentService pour
 * les pièces jointes de créneau) plutôt qu'un dossier plat — plus
 * simple à nettoyer intégralement quand l'annonce est supprimée.
 */
class MarketplaceListingPhotoService
{
    private const ALLOWED_MIME = ['image/jpeg', 'image/png', 'image/webp'];
    private const MAX_BYTES = 8_000_000;
    private const MAX_WIDTH = 1600;

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

        $ext = $file->guessExtension() ?: $file->getClientOriginalExtension() ?: 'jpg';
        $filename = bin2hex(random_bytes(8)).'.'.strtolower($ext);

        try {
            $file->move($dir, $filename);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Échec du déplacement : '.$e->getMessage(), 0, $e);
        }

        $this->resizer->resizeInPlace($dir.\DIRECTORY_SEPARATOR.$filename, $mime, self::MAX_WIDTH);

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
