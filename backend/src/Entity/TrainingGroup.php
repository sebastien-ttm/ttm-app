<?php

namespace App\Entity;

use App\Repository\TrainingGroupRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Groupe d'entraînement « à la volée », créé depuis l'espace Staff par un encadrant / entraîneur juste avant une
 * séance. Éphémère : il reste valable jusqu'au prochain créneau (`expiresAt`, voir TrainingGroupService::plan()) ou
 * jusqu'à ce qu'un encadrant le termine (`closedAt`).
 *
 * Le groupe est adossé à une séance de vélo ou de course à pied (voir `slotTemplate` / `slot`) : un encadrant /
 * entraîneur y a au plus un groupe, et un adhérent n'est que dans un groupe par séance. Le groupe a un nombre maximal
 * d'adhérents (`capacity`), une allure du club de 1 à 5 (`paceLevel`) et une vitesse (`speed`). Il peut être partagé avec
 * d'autres encadrants (`coaches`, qui le gèrent ensemble) ; les adhérents (`members`) le choisissent depuis le créneau
 * jusqu'au début de la séance, ou y sont ajoutés par un encadrant, et le voient dans leur onglet Entraînement, avec les
 * informations, la trace GPX, les commentaires et la position en direct des encadrants.
 */
#[ORM\Entity(repositoryClass: TrainingGroupRepository::class)]
#[ORM\Table(name: 'training_group')]
#[ORM\Index(name: 'idx_training_group_expires', columns: ['expires_at'])]
class TrainingGroup
{
    public const NAME_MAX = 80;
    public const INFO_MAX = 3000;
    public const MAX_MEMBERS = 80;
    public const SPEED_MAX = 60;
    /** Le club est structuré en 5 allures (1 à 5) : le niveau du groupe pour que chacun choisisse le sien. */
    public const PACE_LEVELS = [1, 2, 3, 4, 5];
    /** Sports pour lesquels un groupe peut être créé (valeurs de Sport). */
    public const SPORTS = ['velo', 'course'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** L'encadrant qui a créé le groupe (les autres encadrants le gèrent avec lui via `coaches`). */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $createdBy;

    #[ORM\Column(length: self::NAME_MAX, nullable: true)]
    private ?string $name = null;

    /** Informations pour le groupe (lieu de rendez-vous, programme de la séance…). */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $info = null;

    /** Séance de référence au moment de la création (affichage seulement). */
    #[ORM\Column(length: 160, nullable: true)]
    private ?string $sessionTitle = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $sessionStartsAt = null;

    /**
     * Séance d'entraînement à laquelle le groupe est adossé : un créneau de la semaine type (`slotTemplate` + la
     * semaine `weekStartsAt`) ou un créneau occasionnel (`slot`) — même convention que TrainingSlotRegistration. Les
     * adhérents choisissent un groupe depuis ce créneau, jusqu'à son début. Vélo ou course à pied uniquement. Null pour
     * un groupe créé avant cette règle : il reste géré à la main par ses encadrants, sans choix possible par les adhérents.
     */
    #[ORM\ManyToOne(targetEntity: TrainingSlotTemplate::class)]
    #[ORM\JoinColumn(name: 'slot_template_id', nullable: true, onDelete: 'SET NULL')]
    private ?TrainingSlotTemplate $slotTemplate = null;

    #[ORM\ManyToOne(targetEntity: TrainingSlot::class)]
    #[ORM\JoinColumn(name: 'slot_id', nullable: true, onDelete: 'SET NULL')]
    private ?TrainingSlot $slot = null;

    /** Lundi de la semaine de la séance (avec `slotTemplate`). */
    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $weekStartsAt = null;

    /** Sport de la séance (`velo` ou `course`), figé à la création : sert à l'affichage et au libellé de l'allure. */
    #[ORM\Column(length: 16, nullable: true)]
    private ?string $sport = null;

    /** Nombre maximal d'adhérents (les encadrants ne comptent pas) ; null = groupe ancien, sans limite choisie. */
    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $capacity = null;

    /** Allure du groupe selon l'échelle du club : 1 à 5 (voir PACE_LEVELS) ; null = non précisée. */
    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $paceLevel = null;

    /** Vitesse du groupe, en texte libre (ex : « 5'30 /km », « 28 km/h »), en complément de l'allure. */
    #[ORM\Column(length: self::SPEED_MAX, nullable: true)]
    private ?string $speed = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** Fin de validité : début du prochain créneau après la séance de référence. */
    #[ORM\Column]
    private \DateTimeImmutable $expiresAt;

    /** Terminé à la main par un encadrant (ou plus aucun encadrant) ; null = pas terminé. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $closedAt = null;

    /** @var Collection<int, User> */
    #[ORM\ManyToMany(targetEntity: User::class)]
    #[ORM\JoinTable(name: 'training_group_coach')]
    #[ORM\JoinColumn(name: 'training_group_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'user_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private Collection $coaches;

    /** @var Collection<int, User> */
    #[ORM\ManyToMany(targetEntity: User::class)]
    #[ORM\JoinTable(name: 'training_group_member')]
    #[ORM\JoinColumn(name: 'training_group_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'user_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private Collection $members;

    public function __construct(User $createdBy, \DateTimeImmutable $expiresAt)
    {
        $this->createdBy = $createdBy;
        $this->expiresAt = $expiresAt;
        $this->createdAt = new \DateTimeImmutable();
        $this->coaches = new ArrayCollection();
        $this->members = new ArrayCollection();
        $this->coaches->add($createdBy);
    }

    public function getId(): ?int { return $this->id; }
    public function getCreatedBy(): User { return $this->createdBy; }

    public function getName(): ?string { return $this->name; }
    public function setName(?string $name): self
    {
        $name = $name !== null ? trim($name) : '';
        $this->name = $name === '' ? null : mb_substr($name, 0, self::NAME_MAX);
        return $this;
    }

    /** Nom affiché : le nom choisi, à défaut « Groupe de {prénom de l'encadrant} ». */
    public function displayName(): string
    {
        return $this->name ?? 'Groupe de '.$this->createdBy->getPrenom();
    }

    public function getInfo(): ?string { return $this->info; }
    public function setInfo(?string $info): self
    {
        $info = $info !== null ? trim($info) : '';
        $this->info = $info === '' ? null : mb_substr($info, 0, self::INFO_MAX);
        return $this;
    }

    public function getSessionTitle(): ?string { return $this->sessionTitle; }
    public function getSessionStartsAt(): ?\DateTimeImmutable { return $this->sessionStartsAt; }
    public function setSession(?string $title, ?\DateTimeImmutable $startsAt): self
    {
        $this->sessionTitle = $title !== null ? mb_substr($title, 0, 160) : null;
        $this->sessionStartsAt = $startsAt;
        return $this;
    }

    public function getSlotTemplate(): ?TrainingSlotTemplate { return $this->slotTemplate; }
    public function getSlot(): ?TrainingSlot { return $this->slot; }
    public function getWeekStartsAt(): ?\DateTimeImmutable { return $this->weekStartsAt; }
    public function getSport(): ?string { return $this->sport; }

    /**
     * Adosse le groupe à une séance : un créneau de la semaine type (`$template` + `$weekStartsAt`) OU un créneau
     * occasionnel (`$slot`).
     */
    public function attachToSession(?TrainingSlotTemplate $template, ?TrainingSlot $slot, \DateTimeImmutable $weekStartsAt, string $sport): self
    {
        if (($template === null) === ($slot === null)) {
            throw new \InvalidArgumentException('Un groupe s\'adosse à un créneau de la semaine type OU à un créneau occasionnel.');
        }
        $this->slotTemplate = $template;
        $this->slot = $slot;
        $this->weekStartsAt = $weekStartsAt->modify('monday this week')->setTime(0, 0, 0);
        $this->sport = $sport;
        return $this;
    }

    /** Le groupe est-il adossé à une séance (et donc choisissable par les adhérents) ? */
    public function isAttached(): bool { return $this->slotTemplate !== null || $this->slot !== null; }

    /** Clé de l'occurrence du créneau, identique à celle du planning : « t12:2026-10-26 » ou « s45 » ; null si non adossé. */
    public function occurrenceKey(): ?string
    {
        if ($this->slot !== null) {
            return 's'.$this->slot->getId();
        }
        if ($this->slotTemplate !== null && $this->weekStartsAt !== null) {
            return 't'.$this->slotTemplate->getId().':'.$this->weekStartsAt->format('Y-m-d');
        }
        return null;
    }

    public function getCapacity(): ?int { return $this->capacity; }
    public function setCapacity(?int $capacity): self
    {
        $this->capacity = $capacity === null ? null : max(1, min(self::MAX_MEMBERS, $capacity));
        return $this;
    }

    /** Places restantes (null si le groupe n'a pas de limite). */
    public function placesLeft(): ?int
    {
        return $this->capacity === null ? null : max(0, $this->capacity - $this->members->count());
    }

    public function isFull(): bool { return $this->capacity !== null && $this->members->count() >= $this->capacity; }

    public function getPaceLevel(): ?int { return $this->paceLevel; }
    /** Valeur hors échelle (1 à 5) : ignorée, comme « non précisée ». */
    public function setPaceLevel(?int $level): self
    {
        $this->paceLevel = in_array($level, self::PACE_LEVELS, true) ? $level : null;
        return $this;
    }

    public function getSpeed(): ?string { return $this->speed; }
    public function setSpeed(?string $speed): self
    {
        $speed = $speed !== null ? trim($speed) : '';
        $this->speed = $speed === '' ? null : mb_substr($speed, 0, self::SPEED_MAX);
        return $this;
    }

    /**
     * La séance a-t-elle commencé ? Les adhérents ne choisissent ni ne quittent plus un groupe une fois la séance
     * lancée (l'encadrant peut encore ajouter ou retirer des adhérents).
     */
    public function hasStarted(?\DateTimeImmutable $now = null): bool
    {
        return $this->sessionStartsAt !== null && $this->sessionStartsAt <= ($now ?? new \DateTimeImmutable());
    }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getExpiresAt(): \DateTimeImmutable { return $this->expiresAt; }
    public function getClosedAt(): ?\DateTimeImmutable { return $this->closedAt; }

    public function close(): self
    {
        $this->closedAt ??= new \DateTimeImmutable();
        return $this;
    }

    /** Ni terminé, ni expiré. */
    public function isActive(?\DateTimeImmutable $now = null): bool
    {
        return $this->closedAt === null && $this->expiresAt > ($now ?? new \DateTimeImmutable());
    }

    /** @return Collection<int, User> */
    public function getCoaches(): Collection { return $this->coaches; }

    /** @return Collection<int, User> */
    public function getMembers(): Collection { return $this->members; }

    public function hasCoach(User $user): bool
    {
        foreach ($this->coaches as $c) {
            if ($c->getId() === $user->getId()) {
                return true;
            }
        }
        return false;
    }

    public function hasMember(User $user): bool
    {
        foreach ($this->members as $m) {
            if ($m->getId() === $user->getId()) {
                return true;
            }
        }
        return false;
    }

    public function addCoach(User $user): self
    {
        if (!$this->hasCoach($user)) {
            $this->coaches->add($user);
        }
        return $this;
    }

    public function removeCoach(User $user): self
    {
        foreach ($this->coaches as $c) {
            if ($c->getId() === $user->getId()) {
                $this->coaches->removeElement($c);
            }
        }
        return $this;
    }

    public function addMember(User $user): self
    {
        if (!$this->hasMember($user)) {
            $this->members->add($user);
        }
        return $this;
    }

    public function removeMember(User $user): self
    {
        foreach ($this->members as $m) {
            if ($m->getId() === $user->getId()) {
                $this->members->removeElement($m);
            }
        }
        return $this;
    }

    public function __toString(): string { return $this->displayName(); }
}
