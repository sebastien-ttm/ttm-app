<?php

namespace App\Entity;

use App\Repository\VolunteerRoleRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Fiche de mission d'une campagne de bénévolat, rattachée à un pôle. Deux familles :
 *  - KIND_ACTIVITY : activité du pôle, confiée à un·e ou plusieurs référent·es qui la préparent en amont et la
 *                    pilotent les jours J ;
 *  - KIND_FIELD    : poste terrain, tenu par un·e responsable d'équipe les jours J, rattaché à une activité
 *                    (`activity`) et à ses référent·es.
 *
 * Le détail rédactionnel de la fiche vit dans `content` (JSON) pour rester souple :
 *  - activité : missions[], strengths[], eco, facts[{value,label}], gain, tasks[{phase A|P|O, text, due, links}],
 *               phaseDates[3]? (libellés des trois phases si différents du calendrier standard) ;
 *  - poste    : reportsTo, location, countNote, team, schedule[{when,what}], missions[], rules[], equipment[],
 *               prerequisites[].
 *
 * `manualNames` : personnes déjà en place saisies librement (prénoms), affichées telles quelles en plus des
 * candidatures validées — à remplacer au fil de l'eau par de vrais comptes (affectation par un responsable).
 * `requiredCount` : nombre de personnes recherchées (postes terrain surtout) ; null = non précisé.
 */
#[ORM\Entity(repositoryClass: VolunteerRoleRepository::class)]
#[ORM\Table(name: 'volunteer_role')]
#[ORM\UniqueConstraint(name: 'uniq_volunteer_role_pole_kind_name', columns: ['pole_id', 'kind', 'name'])]
class VolunteerRole
{
    public const KIND_ACTIVITY = 'activity';
    public const KIND_FIELD = 'field';
    public const KINDS = [self::KIND_ACTIVITY, self::KIND_FIELD];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: VolunteerPole::class, inversedBy: 'roles')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private VolunteerPole $pole;

    #[ORM\Column(length: 16)]
    private string $kind = self::KIND_ACTIVITY;

    /** Nom court : l'activité (« Buvette ») ou le poste (« Responsable parkings »). */
    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 120)]
    private string $name = '';

    /** Intitulé du rôle (« Référent·e buvette »). */
    #[ORM\Column(length: 150, nullable: true)]
    #[Assert\Length(max: 150)]
    private ?string $title = null;

    /** Phrase d'accroche de la fiche. */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $pitch = null;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    private array $content = [];

    #[ORM\Column]
    private bool $published = true;

    #[ORM\Column(nullable: true)]
    #[Assert\Positive]
    private ?int $requiredCount = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $manualNames = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $position = 0;

    /** Poste terrain uniquement : activité de rattachement (ses référent·es encadrent le poste). */
    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?VolunteerRole $activity = null;

    /** @var Collection<int, VolunteerApplication> */
    #[ORM\OneToMany(targetEntity: VolunteerApplication::class, mappedBy: 'role', cascade: ['remove'])]
    #[ORM\OrderBy(['createdAt' => 'ASC'])]
    private Collection $applications;

    public function __construct(VolunteerPole $pole, string $kind, string $name)
    {
        $this->pole = $pole;
        $this->setKind($kind);
        $this->name = trim($name);
        $this->applications = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }

    public function getPole(): VolunteerPole { return $this->pole; }
    public function setPole(VolunteerPole $pole): self { $this->pole = $pole; return $this; }

    public function getCampaign(): VolunteerCampaign { return $this->pole->getCampaign(); }

    public function getKind(): string { return $this->kind; }
    public function setKind(string $kind): self
    {
        $this->kind = in_array($kind, self::KINDS, true) ? $kind : self::KIND_ACTIVITY;
        return $this;
    }

    public function isField(): bool { return $this->kind === self::KIND_FIELD; }

    /** Libellé admin du type de fiche. */
    public function getKindLabel(): string
    {
        return $this->isField() ? 'Responsable terrain' : 'Activité';
    }

    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = trim($name); return $this; }

    public function getTitle(): ?string { return $this->title; }
    public function setTitle(?string $t): self { $this->title = $t !== null && trim($t) !== '' ? trim($t) : null; return $this; }

    public function getPitch(): ?string { return $this->pitch; }
    public function setPitch(?string $p): self { $this->pitch = $p !== null && trim($p) !== '' ? trim($p) : null; return $this; }

    /** @return array<string, mixed> */
    public function getContent(): array { return $this->content; }
    /** @param array<string, mixed> $content */
    public function setContent(array $content): self { $this->content = $content; return $this; }

    /** Édition admin du contenu sous forme de JSON indenté. */
    public function getContentJson(): string
    {
        return (string) json_encode($this->content, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
    }

    /** Un JSON invalide est ignoré (le contenu précédent est conservé). */
    public function setContentJson(?string $json): self
    {
        $decoded = json_decode((string) $json, true);
        if (is_array($decoded)) {
            $this->content = $decoded;
        }
        return $this;
    }

    public function isPublished(): bool { return $this->published; }
    public function setPublished(bool $p): self { $this->published = $p; return $this; }

    public function getRequiredCount(): ?int { return $this->requiredCount; }
    public function setRequiredCount(?int $n): self { $this->requiredCount = $n !== null && $n > 0 ? $n : null; return $this; }

    public function getManualNames(): ?string { return $this->manualNames; }
    public function setManualNames(?string $n): self { $this->manualNames = $n !== null && trim($n) !== '' ? trim($n) : null; return $this; }

    public function getPosition(): int { return $this->position; }
    public function setPosition(int $p): self { $this->position = $p; return $this; }

    public function getActivity(): ?VolunteerRole { return $this->activity; }
    public function setActivity(?VolunteerRole $a): self { $this->activity = $a; return $this; }

    /** @return Collection<int, VolunteerApplication> */
    public function getApplications(): Collection { return $this->applications; }

    /** @return list<VolunteerApplication> */
    public function getAcceptedApplications(): array
    {
        return array_values(array_filter(
            $this->applications->toArray(),
            static fn (VolunteerApplication $a) => $a->getStatus() === VolunteerApplication::STATUS_ACCEPTED,
        ));
    }

    public function getPendingCount(): int
    {
        return count(array_filter(
            $this->applications->toArray(),
            static fn (VolunteerApplication $a) => $a->getStatus() === VolunteerApplication::STATUS_PENDING,
        ));
    }

    public function __toString(): string
    {
        return $this->name !== '' ? $this->getKindLabel().' · '.$this->name : '#'.$this->id;
    }
}
