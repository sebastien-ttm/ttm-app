<?php

namespace App\Entity;

use App\Entity\Trait\AudienceAwareTrait;
use App\Entity\Trait\ContentAudienceAwareTrait;
use App\Repository\EventRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[ORM\Entity(repositoryClass: EventRepository::class)]
#[ORM\Table(name: 'event')]
#[ORM\Index(name: 'idx_event_starts_at', columns: ['starts_at'])]
#[ORM\Index(name: 'idx_event_created_by', columns: ['created_by_id'])]
class Event implements OwnedContentInterface
{
    use AudienceAwareTrait;
    use ContentAudienceAwareTrait;

    /** Couleur par défaut si aucun tag n'est associé — gris neutre. */
    public const DEFAULT_COLOR = '#607D8B';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 200)]
    #[Assert\NotBlank]
    private string $title;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $location = null;

    #[ORM\Column]
    #[Assert\NotNull]
    private \DateTimeImmutable $startsAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $endsAt = null;

    /**
     * Tags libres pilotés depuis l'admin (remplace l'ancien enum
     * EventType). Un événement peut porter 0..n tags — sa couleur
     * d'affichage est déduite du premier (position asc).
     *
     * @var Collection<int, EventTag>
     */
    #[ORM\ManyToMany(targetEntity: EventTag::class)]
    #[ORM\JoinTable(name: 'event_event_tag')]
    #[ORM\OrderBy(['position' => 'ASC', 'name' => 'ASC'])]
    private Collection $tags;

    /**
     * Événement « toute la journée » : pas d'heure significative.
     * L'app mobile masque l'heure (et le créneau) si vrai.
     */
    #[ORM\Column(name: 'is_all_day', options: ['default' => false])]
    private bool $isAllDay = false;

    /**
     * Soumis au vote de présence : les adhérents peuvent indiquer
     * « j'y serai », « je n'y serai pas », « je ne sais pas encore ».
     * Défaut FALSE — activation manuelle par l'admin à la création /
     * édition de l'événement (sinon les 3 boutons ne s'affichent pas).
     */
    #[ORM\Column(name: 'vote_enabled', options: ['default' => false])]
    private bool $voteEnabled = false;

    /**
     * Covoiturage activé : les adhérents peuvent proposer des places
     * (conducteur) ou demander à en réserver (passager). Défaut FALSE
     * — activation manuelle à la création / édition.
     */
    #[ORM\Column(name: 'carpooling_enabled', options: ['default' => false])]
    private bool $carpoolingEnabled = false;

    /** Créateur dans le backend — voir ContentDeleteVoter. NULL = antérieur à cette règle. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'created_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy = null;

    /**
     * URL d'inscription externe (compétition avec plateforme tierce
     * type Njuko, klikego, HelloAsso…). Quand renseignée ET que
     * voteEnabled=true, le bouton « J'y serai » côté mobile est
     * remplacé par « Je m'inscris » qui vote « yes » ET ouvre l'URL.
     * NULL = pas d'inscription externe (fonctionnement de vote standard).
     */
    #[ORM\Column(name: 'external_registration_url', length: 500, nullable: true)]
    private ?string $externalRegistrationUrl = null;

    /**
     * Entraînement lié (optionnel) : créneau de la semaine type, dont on
     * affiche l'occurrence de la semaine de l'événement (modifiée ou
     * annulée le cas échéant)…
     */
    #[ORM\ManyToOne(targetEntity: TrainingSlotTemplate::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?TrainingSlotTemplate $trainingSlotTemplate = null;

    /** …ou créneau occasionnel (hors semaine type). */
    #[ORM\ManyToOne(targetEntity: TrainingSlot::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?TrainingSlot $trainingSlot = null;

    public function __construct()
    {
        $this->tags = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }
    public function getTitle(): string { return $this->title; }
    public function setTitle(string $title): self { $this->title = $title; return $this; }
    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): self { $this->description = $description; return $this; }
    public function getLocation(): ?string { return $this->location; }
    public function setLocation(?string $location): self { $this->location = $location; return $this; }
    public function getStartsAt(): \DateTimeImmutable { return $this->startsAt; }
    public function setStartsAt(\DateTimeImmutable $d): self { $this->startsAt = $d; return $this; }
    public function getEndsAt(): ?\DateTimeImmutable { return $this->endsAt; }
    public function setEndsAt(?\DateTimeImmutable $d): self { $this->endsAt = $d; return $this; }

    /** @return Collection<int, EventTag> */
    public function getTags(): Collection { return $this->tags; }

    public function addTag(EventTag $tag): self
    {
        if (!$this->tags->contains($tag)) {
            $this->tags->add($tag);
        }
        return $this;
    }

    public function removeTag(EventTag $tag): self
    {
        $this->tags->removeElement($tag);
        return $this;
    }

    public function isAllDay(): bool { return $this->isAllDay; }
    public function setIsAllDay(bool $v): self { $this->isAllDay = $v; return $this; }
    public function isVoteEnabled(): bool { return $this->voteEnabled; }
    public function setVoteEnabled(bool $v): self { $this->voteEnabled = $v; return $this; }
    public function isCarpoolingEnabled(): bool { return $this->carpoolingEnabled; }
    public function setCarpoolingEnabled(bool $v): self { $this->carpoolingEnabled = $v; return $this; }

    public function getOwner(): ?User { return $this->createdBy; }
    public function getCreatedBy(): ?User { return $this->createdBy; }
    public function setCreatedBy(?User $u): self { $this->createdBy = $u; return $this; }

    public function getTrainingSlotTemplate(): ?TrainingSlotTemplate { return $this->trainingSlotTemplate; }
    public function setTrainingSlotTemplate(?TrainingSlotTemplate $t): self { $this->trainingSlotTemplate = $t; return $this; }
    public function getTrainingSlot(): ?TrainingSlot { return $this->trainingSlot; }
    public function setTrainingSlot(?TrainingSlot $s): self { $this->trainingSlot = $s; return $this; }

    #[Assert\Callback]
    public function validateTrainingLink(ExecutionContextInterface $context): void
    {
        if ($this->trainingSlotTemplate !== null && $this->trainingSlot !== null) {
            $context->buildViolation('Choisissez un créneau récurrent OU un créneau occasionnel, pas les deux.')
                ->atPath('trainingSlot')->addViolation();
        }
    }

    public function getExternalRegistrationUrl(): ?string { return $this->externalRegistrationUrl; }
    public function setExternalRegistrationUrl(?string $u): self
    {
        $t = $u !== null ? trim($u) : null;
        $this->externalRegistrationUrl = ($t === '' ? null : $t);
        return $this;
    }

    /**
     * Couleur d'affichage dérivée du premier tag (ordre asc). Aucune
     * étiquette → gris neutre (DEFAULT_COLOR).
     */
    public function getColor(): string
    {
        $first = $this->tags->first();
        return $first instanceof EventTag ? $first->getColor() : self::DEFAULT_COLOR;
    }

    public function __toString(): string { return $this->title ?? '#'.$this->id; }
}
