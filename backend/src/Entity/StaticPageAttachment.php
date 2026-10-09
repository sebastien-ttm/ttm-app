<?php

namespace App\Entity;

use App\Repository\StaticPageAttachmentRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Pièce jointe d'une page statique (PDF, image, document…). Le fichier
 * est stocké dans var/uploads/page-attachments/{pageId}/ et servi aux
 * adhérents par un endpoint authentifié (jamais en accès public).
 *
 * Miroir de ArticleAttachment — même modèle, même stockage.
 */
#[ORM\Entity(repositoryClass: StaticPageAttachmentRepository::class)]
#[ORM\Table(name: 'static_page_attachment')]
#[ORM\Index(name: 'idx_static_page_attachment_page', columns: ['page_id'])]
class StaticPageAttachment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: StaticPage::class, inversedBy: 'attachments')]
    #[ORM\JoinColumn(name: 'page_id', nullable: false, onDelete: 'CASCADE')]
    private StaticPage $page;

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
        StaticPage $page,
        string $storedName,
        string $originalName,
        string $mimeType,
        int $size,
    ) {
        $this->page = $page;
        $this->storedName = $storedName;
        $this->originalName = $originalName;
        $this->mimeType = $mimeType;
        $this->size = $size;
        $this->uploadedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getPage(): StaticPage { return $this->page; }
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
