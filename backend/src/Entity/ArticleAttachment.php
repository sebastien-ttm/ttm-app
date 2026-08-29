<?php

namespace App\Entity;

use App\Repository\ArticleAttachmentRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Pièce jointe attachée à un article (PDF, image, GPX, doc, etc.).
 * Le fichier est stocké dans var/uploads/article-attachments/{articleId}/.
 * Une PJ appartient à UN article ; pour un partage entre articles, il faut ré-uploader.
 *
 * Miroir de TrainingSlotAttachment — même modèle, même stockage.
 */
#[ORM\Entity(repositoryClass: ArticleAttachmentRepository::class)]
#[ORM\Table(name: 'article_attachment')]
#[ORM\Index(name: 'idx_article_attachment_article', columns: ['article_id'])]
class ArticleAttachment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Article::class, inversedBy: 'attachments')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Article $article;

    /** Nom sur disque (hash pour unicité). */
    #[ORM\Column(length: 255)]
    private string $storedName;

    /** Nom d'origine affiché à l'utilisateur. */
    #[ORM\Column(length: 255)]
    private string $originalName;

    #[ORM\Column(length: 100)]
    private string $mimeType;

    #[ORM\Column(type: 'integer')]
    private int $size;

    #[ORM\Column]
    private \DateTimeImmutable $uploadedAt;

    public function __construct(
        Article $article,
        string $storedName,
        string $originalName,
        string $mimeType,
        int $size,
    ) {
        $this->article = $article;
        $this->storedName = $storedName;
        $this->originalName = $originalName;
        $this->mimeType = $mimeType;
        $this->size = $size;
        $this->uploadedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getArticle(): Article { return $this->article; }
    public function getStoredName(): string { return $this->storedName; }
    public function getOriginalName(): string { return $this->originalName; }
    public function getMimeType(): string { return $this->mimeType; }
    public function getSize(): int { return $this->size; }
    public function getUploadedAt(): \DateTimeImmutable { return $this->uploadedAt; }

    public function getHumanSize(): string
    {
        $units = ['B', 'kB', 'MB', 'GB'];
        $i = 0;
        $s = (float) $this->size;
        while ($s >= 1024 && $i < count($units) - 1) {
            $s /= 1024;
            $i++;
        }
        return sprintf($i === 0 ? '%d %s' : '%.1f %s', $s, $units[$i]);
    }

    public function __toString(): string { return $this->originalName; }
}
