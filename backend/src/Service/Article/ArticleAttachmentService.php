<?php

namespace App\Service\Article;

use App\Entity\Article;
use App\Entity\ArticleAttachment;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Gère le stockage des PJ d'articles.
 * Répertoire physique : var/uploads/article-attachments/{articleId}/.
 * Miroir de App\Service\Training\AttachmentService — même logique.
 */
class ArticleAttachmentService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly string $articleAttachmentsDir,
    ) {
    }

    /**
     * @throws \RuntimeException si l'upload échoue
     */
    public function upload(Article $article, UploadedFile $file): ArticleAttachment
    {
        if ($article->getId() === null) {
            throw new \RuntimeException('L\'article doit être persisté avant d\'attacher un fichier.');
        }

        $dir = $this->articleDir($article);
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

        $att = new ArticleAttachment(
            $article,
            $storedName,
            $original,
            $file->getClientMimeType() ?: 'application/octet-stream',
            (int) filesize($dir.\DIRECTORY_SEPARATOR.$storedName) ?: 0,
        );
        $this->em->persist($att);
        return $att;
    }

    public function remove(ArticleAttachment $att): void
    {
        $path = $this->absolutePath($att);
        if ($path !== null && is_file($path)) {
            @unlink($path);
        }
        $dir = $this->articleDir($att->getArticle());
        if (is_dir($dir) && count(scandir($dir) ?: []) <= 2) {
            @rmdir($dir);
        }
        $this->em->remove($att);
    }

    public function absolutePath(ArticleAttachment $att): ?string
    {
        $article = $att->getArticle();
        if ($article->getId() === null) {
            return null;
        }
        return $this->articleDir($article).\DIRECTORY_SEPARATOR.$att->getStoredName();
    }

    private function articleDir(Article $article): string
    {
        return rtrim($this->articleAttachmentsDir, '/\\').\DIRECTORY_SEPARATOR.(string) $article->getId();
    }
}
