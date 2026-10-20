<?php

namespace App\Service\YouthDocument;

use App\Entity\User;
use App\Entity\YouthDocument;
use App\Enum\YouthDocumentType;
use App\Repository\YouthDocumentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * Stockage des documents officiels des jeunes (fiche sanitaire, autorisation de sortie du territoire) : un seul document
 * par (jeune, type), fichier sous var/uploads/youth-documents/{jeune}/ — hors du dossier public, servi uniquement par
 * l'API authentifiée.
 */
class YouthDocumentService
{
    /** Taille maximale d'un document (une photo de téléphone tient largement, un scan PDF aussi). */
    public const MAX_BYTES = 10_000_000;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly YouthDocumentRepository $documents,
        private readonly string $youthDocumentsDir,
    ) {
    }

    /**
     * Type réel d'un fichier d'après son contenu (jamais d'après son nom ni le type annoncé par le navigateur) :
     * PDF, JPEG, PNG ou WebP. null pour tout autre fichier.
     *
     * @return array{mime: string, ext: string}|null
     */
    public static function sniff(string $path): ?array
    {
        $head = (string) @file_get_contents($path, false, null, 0, 16);
        if (str_starts_with($head, '%PDF-')) {
            return ['mime' => 'application/pdf', 'ext' => 'pdf'];
        }
        if (str_starts_with($head, "\xFF\xD8\xFF")) {
            return ['mime' => 'image/jpeg', 'ext' => 'jpg'];
        }
        if (str_starts_with($head, "\x89PNG\r\n\x1a\n")) {
            return ['mime' => 'image/png', 'ext' => 'png'];
        }
        if (strlen($head) >= 12 && str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP') {
            return ['mime' => 'image/webp', 'ext' => 'webp'];
        }

        return null;
    }

    /**
     * Enregistre le fichier comme document du jeune (le remplace s'il en existe déjà un de ce type). Le flush reste à
     * l'appelant.
     *
     * @param array{mime: string, ext: string} $kind résultat de sniff()
     *
     * @throws \RuntimeException si le fichier ne peut pas être enregistré
     */
    public function store(User $owner, YouthDocumentType $type, UploadedFile $file, array $kind, User $by, ?\DateTimeImmutable $validUntil): YouthDocument
    {
        $dir = $this->ownerDir($owner);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('Impossible de créer le dossier %s', $dir));
        }

        $original = $file->getClientOriginalName();
        $storedName = bin2hex(random_bytes(12)).'.'.$kind['ext'];
        try {
            $file->move($dir, $storedName);
        } catch (\Exception $e) {
            throw new \RuntimeException('Échec de l\'enregistrement du fichier : '.$e->getMessage(), 0, $e);
        }
        $size = (int) filesize($dir.\DIRECTORY_SEPARATOR.$storedName);

        $existing = $this->documents->findOneByOwnerAndType($owner, $type);
        if ($existing !== null) {
            $this->deleteFile($existing);
            $existing->replaceFile($storedName, $original, $kind['mime'], $size, $by, $validUntil);

            return $existing;
        }

        $document = new YouthDocument($owner, $type, $storedName, $original, $kind['mime'], $size, $by, $validUntil);
        $this->em->persist($document);

        return $document;
    }

    public function remove(YouthDocument $document): void
    {
        $this->deleteFile($document);
        $this->em->remove($document);
    }

    public function absolutePath(YouthDocument $document): ?string
    {
        if ($document->getOwner()->getId() === null) {
            return null;
        }

        return $this->ownerDir($document->getOwner()).\DIRECTORY_SEPARATOR.$document->getStoredName();
    }

    /**
     * Réponse fichier : PDF et images s'affichent dans le navigateur (ouverture rapide au bord du bassin). Jamais mis en
     * cache, jamais reniflé.
     */
    public function fileResponse(YouthDocument $document): ?BinaryFileResponse
    {
        $path = $this->absolutePath($document);
        if ($path === null || !is_file($path)) {
            return null;
        }

        $name = $document->getOriginalName();
        $response = new BinaryFileResponse($path);
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_INLINE,
            $name,
            // Repli ASCII obligatoire pour les noms accentués.
            (string) preg_replace('/[^\x20-\x7e]|[\/\\\\%"]/', '_', $name),
        );
        $response->headers->set('Content-Type', $document->getMimeType());
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    private function deleteFile(YouthDocument $document): void
    {
        $path = $this->absolutePath($document);
        if ($path !== null && is_file($path)) {
            @unlink($path);
        }
    }

    private function ownerDir(User $owner): string
    {
        return rtrim($this->youthDocumentsDir, '/\\').\DIRECTORY_SEPARATOR.(string) $owner->getId();
    }
}
