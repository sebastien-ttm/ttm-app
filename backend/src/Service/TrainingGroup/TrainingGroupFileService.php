<?php

namespace App\Service\TrainingGroup;

use App\Entity\TrainingGroup;
use App\Entity\TrainingGroupAttachment;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * Stockage des fichiers (traces GPX) des groupes d'entraînement : var/uploads/training-groups/{groupId}/.
 * Même logique que ArticleAttachmentService.
 */
class TrainingGroupFileService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly string $trainingGroupsDir,
    ) {
    }

    /**
     * Enregistre le fichier et crée la pièce jointe (l'entité est persistée ici, le flush reste à l'appelant).
     *
     * @throws \RuntimeException si le fichier ne peut pas être enregistré
     */
    public function upload(TrainingGroup $group, User $by, UploadedFile $file): TrainingGroupAttachment
    {
        if ($group->getId() === null) {
            throw new \RuntimeException('Le groupe doit être enregistré avant d\'y joindre un fichier.');
        }

        $dir = $this->groupDir($group);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('Impossible de créer le dossier %s', $dir));
        }

        $original = $file->getClientOriginalName();
        $ext = strtolower($file->getClientOriginalExtension());
        $storedName = bin2hex(random_bytes(8)).($ext !== '' ? '.'.$ext : '');
        $mime = $file->getClientMimeType() ?: 'application/octet-stream';

        try {
            $file->move($dir, $storedName);
        } catch (\Exception $e) {
            throw new \RuntimeException('Échec de l\'enregistrement du fichier : '.$e->getMessage(), 0, $e);
        }

        $att = new TrainingGroupAttachment(
            $group,
            $by,
            $storedName,
            $original,
            $mime,
            (int) filesize($dir.\DIRECTORY_SEPARATOR.$storedName),
        );
        $this->em->persist($att);

        return $att;
    }

    public function remove(TrainingGroupAttachment $att): void
    {
        $path = $this->absolutePath($att);
        if ($path !== null && is_file($path)) {
            @unlink($path);
        }
        $dir = $this->groupDir($att->getTrainingGroup());
        if (is_dir($dir) && count(scandir($dir) ?: []) <= 2) {
            @rmdir($dir);
        }
        $this->em->remove($att);
    }

    /** Supprime tous les fichiers d'un groupe (les lignes en base partent avec le groupe, en cascade). */
    public function removeFiles(TrainingGroup $group): void
    {
        $dir = $this->groupDir($group);
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $name) {
            if ($name !== '.' && $name !== '..' && is_file($dir.\DIRECTORY_SEPARATOR.$name)) {
                @unlink($dir.\DIRECTORY_SEPARATOR.$name);
            }
        }
        @rmdir($dir);
    }

    public function absolutePath(TrainingGroupAttachment $att): ?string
    {
        $group = $att->getTrainingGroup();
        if ($group->getId() === null) {
            return null;
        }

        return $this->groupDir($group).\DIRECTORY_SEPARATOR.$att->getStoredName();
    }

    /** Réponse fichier : un GPX est servi en téléchargement, avec son type propre pour que le téléphone propose les bonnes applis. */
    public function fileResponse(TrainingGroupAttachment $att): ?BinaryFileResponse
    {
        $path = $this->absolutePath($att);
        if ($path === null || !is_file($path)) {
            return null;
        }
        $name = $att->getOriginalName();
        $mime = preg_match('/\.gpx$/i', $name) ? 'application/gpx+xml' : $att->getMimeType();

        $resp = new BinaryFileResponse($path);
        $resp->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $name,
            // Repli ASCII obligatoire pour les noms accentués.
            (string) preg_replace('/[^\x20-\x7e]|[\/\\\\%"]/', '_', $name),
        );
        $resp->headers->set('Content-Type', $mime);

        return $resp;
    }

    private function groupDir(TrainingGroup $group): string
    {
        return rtrim($this->trainingGroupsDir, '/\\').\DIRECTORY_SEPARATOR.(string) $group->getId();
    }
}
