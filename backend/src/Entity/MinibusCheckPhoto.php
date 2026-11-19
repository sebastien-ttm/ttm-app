<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Photo d'un état des lieux du minibus. Le fichier (JPEG réduit) est stocké hors de la partie publique du site, sous
 * var/uploads/minibus/{emprunt}/, et n'est servi que par l'API authentifiée aux membres du CoDir (voir MinibusPhotoService).
 */
#[ORM\Entity]
#[ORM\Table(name: 'minibus_check_photo')]
#[ORM\Index(name: 'idx_minibus_photo_check', columns: ['check_id'])]
class MinibusCheckPhoto
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: MinibusCheck::class, inversedBy: 'photos')]
    #[ORM\JoinColumn(name: 'check_id', nullable: false, onDelete: 'CASCADE')]
    private MinibusCheck $minibusCheck;

    /** Nom du fichier sur le disque (aléatoire, jamais celui envoyé par le navigateur). */
    #[ORM\Column(name: 'stored_name', length: 255)]
    private string $storedName;

    #[ORM\Column]
    private int $position = 0;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    public function __construct(MinibusCheck $check, string $storedName, int $position)
    {
        $this->minibusCheck = $check;
        $this->storedName = $storedName;
        $this->position = $position;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getCheck(): MinibusCheck { return $this->minibusCheck; }
    public function getStoredName(): string { return $this->storedName; }
    public function getPosition(): int { return $this->position; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
