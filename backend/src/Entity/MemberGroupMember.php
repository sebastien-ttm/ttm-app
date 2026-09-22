<?php

namespace App\Entity;

use App\Repository\MemberGroupMemberRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Liaison (groupe, user) avec métadonnées — remplace un M2M nu pour
 * pouvoir tracer la date et la provenance de chaque adhésion (vote
 * d'événement, réponse à un sondage, ajout admin).
 */
#[ORM\Entity(repositoryClass: MemberGroupMemberRepository::class)]
#[ORM\Table(name: 'member_group_member')]
#[ORM\UniqueConstraint(name: 'uniq_group_user', columns: ['group_id', 'user_id'])]
#[ORM\Index(name: 'idx_mgm_user', columns: ['user_id'])]
class MemberGroupMember
{
    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_EVENT_VOTE = 'event_vote';
    public const SOURCE_SURVEY_ANSWER = 'survey_answer';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: MemberGroup::class, inversedBy: 'memberships')]
    #[ORM\JoinColumn(name: 'group_id', nullable: false, onDelete: 'CASCADE')]
    private MemberGroup $group;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column]
    private \DateTimeImmutable $joinedAt;

    /** Comment l'adhésion a été créée. Voir constantes SOURCE_*. */
    #[ORM\Column(length: 20, options: ['default' => self::SOURCE_MANUAL])]
    private string $source = self::SOURCE_MANUAL;

    public function __construct(MemberGroup $group, User $user, string $source = self::SOURCE_MANUAL)
    {
        $this->group = $group;
        $this->user = $user;
        $this->source = $source;
        $this->joinedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getGroup(): MemberGroup { return $this->group; }
    public function getUser(): User { return $this->user; }
    public function getJoinedAt(): \DateTimeImmutable { return $this->joinedAt; }
    public function getSource(): string { return $this->source; }
    public function setSource(string $source): self { $this->source = $source; return $this; }
}
