<?php

namespace App\Entity;

use App\Repository\PhotoAlbumCoverRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Photo qui représente un album de « Photos du club » dans la liste des albums, et son cadrage. Les albums vivent dans
 * Piwigo (qui choisit seul une photo représentative) : on garde ici le choix de l'adhérent, qui prévaut. Le cadrage est
 * la position de la photo dans la vignette, en % (comme `object-position` : 50/50 = centrée).
 */
#[ORM\Entity(repositoryClass: PhotoAlbumCoverRepository::class)]
#[ORM\Table(name: 'photo_album_cover')]
#[ORM\UniqueConstraint(name: 'uniq_photo_album_cover_album', columns: ['piwigo_album_id'])]
class PhotoAlbumCover
{
    public const CENTER = 50;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private int $piwigoAlbumId;

    /** Photo choisie (id Piwigo) ; null = celle que Piwigo propose. */
    #[ORM\Column(nullable: true)]
    private ?int $coverImageId = null;

    #[ORM\Column(type: 'smallint', options: ['default' => 50])]
    private int $focusX = self::CENTER;

    #[ORM\Column(type: 'smallint', options: ['default' => 50])]
    private int $focusY = self::CENTER;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(int $piwigoAlbumId)
    {
        $this->piwigoAlbumId = $piwigoAlbumId;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getPiwigoAlbumId(): int { return $this->piwigoAlbumId; }
    public function getCoverImageId(): ?int { return $this->coverImageId; }
    public function getFocusX(): int { return $this->focusX; }
    public function getFocusY(): int { return $this->focusY; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    /** Choisit la photo et son cadrage (valeurs ramenées entre 0 et 100). */
    public function set(?int $imageId, int $focusX, int $focusY): self
    {
        $this->coverImageId = $imageId;
        $this->focusX = max(0, min(100, $focusX));
        $this->focusY = max(0, min(100, $focusY));
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }
}
