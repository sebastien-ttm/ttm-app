<?php

namespace App\Entity;

use App\Repository\PhotoUploadRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Trace d'une photo envoyée depuis l'appli dans la galerie Piwigo
 * (« Photos du club ») : qui l'a postée, dans quel album. Sert à
 * afficher l'auteur, à lui permettre de la supprimer, et à la
 * modération admin. La photo elle-même vit dans Piwigo.
 */
#[ORM\Entity(repositoryClass: PhotoUploadRepository::class)]
#[ORM\Table(name: 'photo_upload')]
#[ORM\UniqueConstraint(name: 'uniq_photo_upload_piwigo_image', columns: ['piwigo_image_id'])]
#[ORM\Index(name: 'idx_photo_upload_user_created', columns: ['user_id', 'created_at'])]
class PhotoUpload
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column]
    private int $piwigoImageId;

    #[ORM\Column]
    private int $piwigoAlbumId;

    /** Nom de l'album au moment de l'envoi (affichage admin). */
    #[ORM\Column(length: 255)]
    private string $albumName;

    /** Page de la photo sur le site Piwigo (lien admin). */
    #[ORM\Column(length: 500, nullable: true)]
    private ?string $piwigoUrl;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(User $user, int $piwigoImageId, int $piwigoAlbumId, string $albumName, ?string $piwigoUrl)
    {
        $this->user = $user;
        $this->piwigoImageId = $piwigoImageId;
        $this->piwigoAlbumId = $piwigoAlbumId;
        $this->albumName = mb_substr($albumName, 0, 255);
        $this->piwigoUrl = $piwigoUrl !== null ? mb_substr($piwigoUrl, 0, 500) : null;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getUser(): User { return $this->user; }
    public function getPiwigoImageId(): int { return $this->piwigoImageId; }
    public function getPiwigoAlbumId(): int { return $this->piwigoAlbumId; }
    public function getAlbumName(): string { return $this->albumName; }
    public function getPiwigoUrl(): ?string { return $this->piwigoUrl; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function __toString(): string
    {
        return 'Photo #'.$this->piwigoImageId.' — '.$this->albumName;
    }
}
