<?php

namespace App\Service\StaticPage;

use App\Entity\StaticPage;
use App\Entity\StaticPageAttachment;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Gère le stockage des PJ de pages statiques.
 * Répertoire physique : var/uploads/page-attachments/{pageId}/.
 * Miroir de App\Service\Article\ArticleAttachmentService.
 */
class StaticPageAttachmentService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly string $pageAttachmentsDir,
    ) {
    }

    /**
     * @throws \RuntimeException si l'upload échoue
     */
    public function upload(StaticPage $page, UploadedFile $file): StaticPageAttachment
    {
        if ($page->getId() === null) {
            throw new \RuntimeException('La page doit être enregistrée avant d\'attacher un fichier.');
        }

        $dir = $this->pageDir($page);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('Impossible de créer le dossier %s', $dir));
        }

        $original = $file->getClientOriginalName();
        $ext = $file->getClientOriginalExtension();
        $storedName = bin2hex(random_bytes(8)).($ext ? '.'.strtolower($ext) : '');

        try {
            $file->move($dir, $storedName);
        } catch (\Exception $e) {
            throw new \RuntimeException('Échec du déplacement du fichier : '.$e->getMessage(), 0, $e);
        }

        $att = new StaticPageAttachment(
            $page,
            $storedName,
            $original,
            $file->getClientMimeType() ?: 'application/octet-stream',
            (int) filesize($dir.\DIRECTORY_SEPARATOR.$storedName) ?: 0,
        );
        $this->em->persist($att);
        return $att;
    }

    public function remove(StaticPageAttachment $att): void
    {
        $path = $this->absolutePath($att);
        if ($path !== null && is_file($path)) {
            @unlink($path);
        }
        $dir = $this->pageDir($att->getPage());
        if (is_dir($dir) && count(scandir($dir) ?: []) <= 2) {
            @rmdir($dir);
        }
        $this->em->remove($att);
    }

    public function absolutePath(StaticPageAttachment $att): ?string
    {
        $page = $att->getPage();
        if ($page->getId() === null) {
            return null;
        }
        return $this->pageDir($page).\DIRECTORY_SEPARATOR.$att->getStoredName();
    }

    private function pageDir(StaticPage $page): string
    {
        return rtrim($this->pageAttachmentsDir, '/\\').\DIRECTORY_SEPARATOR.(string) $page->getId();
    }
}
