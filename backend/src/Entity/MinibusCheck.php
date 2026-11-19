<?php

namespace App\Entity;

use App\Enum\MinibusCheckType;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * État des lieux du minibus, rempli par l'emprunteur dans l'appli : un au départ, un au retour (au plus un de chaque type
 * par emprunt). Il remplace l'ancien formulaire Google des fiches du minibus : photos du tour complet du minibus et de la
 * remorque, comment ça s'est passé, problèmes éventuels, et au retour le plein fait / le minibus nettoyé et vidé.
 */
#[ORM\Entity]
#[ORM\Table(name: 'minibus_check')]
#[ORM\UniqueConstraint(name: 'uniq_minibus_check_booking_type', columns: ['booking_id', 'type'])]
class MinibusCheck
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: MinibusBooking::class, inversedBy: 'checks')]
    #[ORM\JoinColumn(name: 'booking_id', nullable: false, onDelete: 'CASCADE')]
    private MinibusBooking $booking;

    #[ORM\Column(length: 16, enumType: MinibusCheckType::class)]
    private MinibusCheckType $type;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'submitted_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $submittedBy;

    #[ORM\Column(name: 'submitted_by_name', length: 200)]
    private string $submittedByName;

    #[ORM\Column(name: 'submitted_at')]
    private \DateTimeImmutable $submittedAt;

    /** « Comment ça s'est passé » (texte libre). */
    #[ORM\Column(name: 'comment_text', type: 'text', nullable: true)]
    private ?string $comment = null;

    /** Un problème (dégât, panne, incident…) est-il à signaler ? Si oui, `problemDescription` le décrit. */
    #[ORM\Column(name: 'has_problem', options: ['default' => false])]
    private bool $hasProblem = false;

    #[ORM\Column(name: 'problem_description', type: 'text', nullable: true)]
    private ?string $problemDescription = null;

    /** Kilométrage relevé au compteur (facultatif). */
    #[ORM\Column(nullable: true)]
    private ?int $mileage = null;

    /** Au retour : le plein a-t-il été refait ? / le minibus a-t-il été nettoyé et vidé de tout détritus ? */
    #[ORM\Column(options: ['default' => false])]
    private bool $refueled = false;

    #[ORM\Column(options: ['default' => false])]
    private bool $cleaned = false;

    /** @var Collection<int, MinibusCheckPhoto> */
    #[ORM\OneToMany(targetEntity: MinibusCheckPhoto::class, mappedBy: 'minibusCheck', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    private Collection $photos;

    public function __construct(MinibusBooking $booking, MinibusCheckType $type, User $by)
    {
        $this->booking = $booking;
        $this->type = $type;
        $this->submittedBy = $by;
        $this->submittedByName = $by->getFullName();
        $this->submittedAt = new \DateTimeImmutable();
        $this->photos = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }
    public function getBooking(): MinibusBooking { return $this->booking; }
    public function getType(): MinibusCheckType { return $this->type; }
    public function getSubmittedBy(): ?User { return $this->submittedBy; }
    public function getSubmittedByName(): string { return $this->submittedByName; }
    public function getSubmittedAt(): \DateTimeImmutable { return $this->submittedAt; }

    public function getComment(): ?string { return $this->comment; }
    public function setComment(?string $comment): self
    {
        $this->comment = $comment !== null && trim($comment) !== '' ? trim($comment) : null;
        return $this;
    }

    public function hasProblem(): bool { return $this->hasProblem; }
    public function getProblemDescription(): ?string { return $this->problemDescription; }
    /** Signale (ou retire) un problème ; sans problème, la description est effacée. */
    public function setProblem(bool $hasProblem, ?string $description): self
    {
        $this->hasProblem = $hasProblem;
        $this->problemDescription = $hasProblem && $description !== null && trim($description) !== '' ? trim($description) : null;
        return $this;
    }

    public function getMileage(): ?int { return $this->mileage; }
    public function setMileage(?int $mileage): self { $this->mileage = $mileage !== null && $mileage >= 0 ? $mileage : null; return $this; }

    public function isRefueled(): bool { return $this->refueled; }
    public function isCleaned(): bool { return $this->cleaned; }
    public function setReturnChecklist(bool $refueled, bool $cleaned): self
    {
        $this->refueled = $refueled;
        $this->cleaned = $cleaned;
        return $this;
    }

    /** @return Collection<int, MinibusCheckPhoto> */
    public function getPhotos(): Collection { return $this->photos; }

    public function addPhoto(MinibusCheckPhoto $photo): self
    {
        if (!$this->photos->contains($photo)) {
            $this->photos->add($photo);
        }
        return $this;
    }

    public function removePhoto(MinibusCheckPhoto $photo): self
    {
        $this->photos->removeElement($photo);
        return $this;
    }
}
