<?php

namespace App\Service\Training;

use App\Entity\TrainingSlot;
use App\Entity\TrainingSlotAttachment;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

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
