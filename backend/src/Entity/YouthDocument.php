<?php

namespace App\Entity;

use App\Enum\YouthDocumentType;
use App\Repository\YouthDocumentRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Document officiel d'un adhérent jeune : fiche sanitaire ou autorisation de sortie du territoire, déposé par le jeune,
 * l'un de ses parents ou le staff, consultable par eux et par le staff (entraîneurs, encadrants) et les administrateurs.
 * Données sensibles : fichier stocké hors du dossier public (var/uploads/youth-documents/{jeune}/), servi uniquement par
 * l'API authentifiée.
 *
 * Un seul document par (jeune, type) : un nouvel envoi remplace le précédent (voir replaceFile).
 */
#[ORM\Entity(repositoryClass: YouthDocumentRepository::class)]
#[ORM\Table(name: 'youth_document')]
#[ORM\UniqueConstraint(name: 'uniq_youth_document_owner_type', columns: ['owner_id', 'type'])]
class YouthDocument
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Le jeune à qui appartient le document. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'owner_id', nullable: false, onDelete: 'CASCADE')]
    private User $owner;

    #[ORM\Column(length: 24, enumType: YouthDocumentType::class)]
    private YouthDocumentType $type;

    #[ORM\Column(length: 64)]
    private string $storedName;

    #[ORM\Column(length: 255)]
    private string $originalName;

    #[ORM\Column(length: 100)]
    private string $mimeType;

    #[ORM\Column]
    private int $size;

    /** Qui a déposé le fichier (null si ce compte a été supprimé depuis). */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'uploaded_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $uploadedBy = null;

    #[ORM\Column]
    private \DateTimeImmutable $uploadedAt;

    /** Fin de validité si elle est connue (typiquement l'autorisation de sortie du territoire) ; null = sans échéance. */
    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $validUntil = null;

    public function __construct(User $owner, YouthDocumentType $type, string $storedName, string $originalName, string $mimeType, int $size, ?User $uploadedBy, ?\DateTimeImmutable $validUntil)
    {
        $this->owner = $owner;
        $this->type = $type;
        $this->replaceFile($storedName, $originalName, $mimeType, $size, $uploadedBy, $validUntil);
    }

    /** Remplace le fichier (nouvel envoi du même type) : le document garde son identité, sa date d'envoi est renouvelée. */
    public function replaceFile(string $storedName, string $originalName, string $mimeType, int $size, ?User $uploadedBy, ?\DateTimeImmutable $validUntil): self
    {
        $this->storedName = $storedName;
        $this->originalName = mb_substr($originalName, 0, 255);
        $this->mimeType = mb_substr($mimeType, 0, 100);
        $this->size = $size;
        $this->uploadedBy = $uploadedBy;
        $this->uploadedAt = new \DateTimeImmutable();
        $this->validUntil = $validUntil;

        return $this;
    }

    public function getId(): ?int { return $this->id; }
    public function getOwner(): User { return $this->owner; }
    public function getType(): YouthDocumentType { return $this->type; }
    public function getStoredName(): string { return $this->storedName; }
    public function getOriginalName(): string { return $this->originalName; }
    public function getMimeType(): string { return $this->mimeType; }
    public function getSize(): int { return $this->size; }
    public function getUploadedBy(): ?User { return $this->uploadedBy; }
    public function getUploadedAt(): \DateTimeImmutable { return $this->uploadedAt; }
    public function getValidUntil(): ?\DateTimeImmutable { return $this->validUntil; }

    /** La date de fin de validité est dépassée (le jour même, le document est encore valable). */
    public function isExpired(?\DateTimeImmutable $today = null): bool
    {
        return $this->validUntil !== null && $this->validUntil < ($today ?? new \DateTimeImmutable('today'));
    }

    public function getHumanSize(): string
    {
        $units = ['B', 'kB', 'MB', 'GB'];
        $i = 0;
        $s = (float) $this->size;
        while ($s >= 1024 && $i < count($units) - 1) {
            $s /= 1024;
            ++$i;
        }

        return sprintf($i === 0 ? '%d %s' : '%.1f %s', $s, $units[$i]);
    }
}
