<?php

namespace App\Service\Training;

use App\Entity\TrainingSlot;
use App\Entity\TrainingSlotAttachment;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * Gère le stockage des PJ de créneaux d'entraînement.
 * Le répertoire physique est dans var/uploads/training-slots/{slotId}/.
 */
class AttachmentService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly string $trainingSlotsDir,
    ) {
    }

    /**
     * Réponse fichier d'une PJ. PDF / images : affichés (inline) ; le
     * reste (GPX, FIT…) : téléchargé. null si le fichier a disparu.
     */
    public function fileResponse(TrainingSlotAttachment $att): ?BinaryFileResponse
    {
        $path = $this->absolutePath($att);
        if ($path === null || !is_file($path)) {
            return null;
        }
        $name = $att->getOriginalName();
        $mime = $att->getMimeType();
        // Les GPX arrivent souvent en application/octet-stream ou text/xml
        // selon le navigateur qui les a envoyés : type explicite pour que
        // le téléphone propose les bonnes applis (Komoot, OsmAnd, Garmin…).
        if (preg_match('/\.gpx$/i', $name)) {
            $mime = 'application/gpx+xml';
        }
        $viewable = $mime === 'application/pdf' || str_starts_with($mime, 'image/');

        $resp = new BinaryFileResponse($path);
        $resp->setContentDisposition(
            $viewable ? ResponseHeaderBag::DISPOSITION_INLINE : ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $name,
            // Repli ASCII obligatoire pour les noms accentués.
            (string) preg_replace('/[^\x20-\x7e]|[\/\\\\%"]/', '_', $name),
        );
        $resp->headers->set('Content-Type', $mime);
        return $resp;
    }

    /**
     * Sauvegarde un fichier uploadé et crée l'entité PJ.
     * @throws \RuntimeException si l'upload échoue
     */
    public function upload(TrainingSlot $slot, UploadedFile $file): TrainingSlotAttachment
    {
        if ($slot->getId() === null) {
            throw new \RuntimeException('Le créneau doit être persisté avant d\'attacher un fichier.');
        }

        $dir = $this->slotDir($slot);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('Impossible de créer le dossier %s', $dir));
        }

        $original = $file->getClientOriginalName();
        $ext = $file->getClientOriginalExtension();
        // Nom sur disque : hash pour éviter les collisions et caractères exotiques
        $storedName = bin2hex(random_bytes(8)).($ext ? '.'.strtolower($ext) : '');

        try {
            $file->move($dir, $storedName);
        } catch (\Exception $e) {
            throw new \RuntimeException('Échec du déplacement du fichier : '.$e->getMessage(), 0, $e);
        }

        $att = new TrainingSlotAttachment(
            $slot,
            $storedName,
            $original,
            $file->getClientMimeType() ?: 'application/octet-stream',
            (int) filesize($dir.\DIRECTORY_SEPARATOR.$storedName) ?: 0,
        );
        $this->em->persist($att);
        return $att;
    }

    public function remove(TrainingSlotAttachment $att): void
    {
        $path = $this->absolutePath($att);
        if ($path !== null && is_file($path)) {
            @unlink($path);
        }
        // Optionnel : si le dossier devient vide, le supprimer
        $dir = $this->slotDir($att->getSlot());
        if (is_dir($dir) && count(scandir($dir) ?: []) <= 2) {
            @rmdir($dir);
        }
        $this->em->remove($att);
    }

    public function absolutePath(TrainingSlotAttachment $att): ?string
    {
        $slot = $att->getSlot();
        if ($slot->getId() === null) {
            return null;
        }
        return $this->slotDir($slot).\DIRECTORY_SEPARATOR.$att->getStoredName();
    }

    private function slotDir(TrainingSlot $slot): string
    {
        return rtrim($this->trainingSlotsDir, '/\\').\DIRECTORY_SEPARATOR.(string) $slot->getId();
    }
}
