<?php

namespace App\Entity;

use App\Repository\MemberGroupRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Groupe d'adhérents rattaché à une saison. 3 sources d'alimentation :
 *  - SOURCE_MANUAL  : créé et rempli à la main par un admin.
 *  - SOURCE_EVENT   : créé automatiquement pour un événement à vote de
 *                     présence — s'auto-remplit avec les votes « yes ».
 *  - SOURCE_SURVEY  : créé/rempli via une réponse de sondage — voir
 *                     le service MemberGroupService::syncSurveyGroupTargets.
 *
 * L'unicité (season, name) autorise à réutiliser le même nom d'une
 * saison à l'autre — pratique pour un « Stage Banyuls » annuel.
 */
#[ORM\Entity(repositoryClass: MemberGroupRepository::class)]
#[ORM\Table(name: 'member_group')]
#[ORM\UniqueConstraint(name: 'uniq_member_group_season_name', columns: ['season_id', 'name'])]
#[ORM\Index(name: 'idx_member_group_source_event', columns: ['source_event_id'])]
class MemberGroup
{
    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_EVENT  = 'event';
    public const SOURCE_SURVEY = 'survey';

    public const SOURCES = [self::SOURCE_MANUAL, self::SOURCE_EVENT, self::SOURCE_SURVEY];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: TrainingSeason::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private TrainingSeason $season;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 120)]
    private string $name = '';

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    /**
     * Provenance du groupe (voir constantes SOURCE_*).
     */
    #[ORM\Column(length: 16, options: ['default' => self::SOURCE_MANUAL])]
    private string $source = self::SOURCE_MANUAL;

    /**
     * Rattachement fort à l'événement d'origine quand source=event.
     * ON DELETE SET NULL : supprimer l'événement ne perd pas l'historique
     * du groupe (on veut garder la trace des membres pour l'admin même
     * après suppression de l'événement).
     */
    #[ORM\ManyToOne(targetEntity: Event::class)]
    #[ORM\JoinColumn(name: 'source_event_id', nullable: true, onDelete: 'SET NULL')]
    private ?Event $sourceEvent = null;

    /**
     * Identifiant textuel de la question qui a alimenté le groupe quand
     * source=survey. Dénormalisé (les questions vivent dans le JSON du
     * sondage — pas de FK possible). Utile pour audit / debug.
     */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $sourceSurveyQuestion = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /**
     * @var Collection<int, MemberGroupMember>
     */
    #[ORM\OneToMany(targetEntity: MemberGroupMember::class, mappedBy: 'group', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['joinedAt' => 'DESC'])]
    private Collection $memberships;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->memberships = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }

    public function getSeason(): TrainingSeason { return $this->season; }
    public function setSeason(TrainingSeason $season): self { $this->season = $season; return $this; }

    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = trim($name); return $this; }

    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): self { $this->description = $description; return $this; }

    public function getSource(): string { return $this->source; }
    public function setSource(string $source): self
    {
        $this->source = in_array($source, self::SOURCES, true) ? $source : self::SOURCE_MANUAL;
        return $this;
    }

    public function getSourceEvent(): ?Event { return $this->sourceEvent; }
    public function setSourceEvent(?Event $event): self { $this->sourceEvent = $event; return $this; }

    public function getSourceSurveyQuestion(): ?string { return $this->sourceSurveyQuestion; }
    public function setSourceSurveyQuestion(?string $qid): self { $this->sourceSurveyQuestion = $qid; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    /** @return Collection<int, MemberGroupMember> */
    public function getMemberships(): Collection { return $this->memberships; }

    /** Nombre d'adhérents dans le groupe — helper d'affichage EA. */
    public function getMemberCount(): int { return $this->memberships->count(); }

    public function __toString(): string
    {
        return $this->name !== '' ? $this->name : '#'.$this->id;
    }
}
