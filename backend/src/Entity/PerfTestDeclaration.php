<?php

namespace App\Entity;

use App\Enum\PerfTest;
use App\Repository\PerfTestDeclarationRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Temps déclaré par un adhérent après une prise de temps individuelle
 * (depuis l'appli). Les entraîneurs et l'admin l'acceptent — le temps est
 * alors enregistré sur une séance « individuelle » du jour — ou le refusent.
 */
#[ORM\Entity(repositoryClass: PerfTestDeclarationRepository::class)]
#[ORM\Table(name: 'perf_test_declaration')]
#[ORM\Index(name: 'idx_perf_declaration_status', columns: ['status', 'created_at'])]
#[ORM\Index(name: 'idx_perf_declaration_user', columns: ['user_id'])]
class PerfTestDeclaration
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_REJECTED = 'rejected';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 32, enumType: PerfTest::class)]
    private PerfTest $test;

    /** Longueur du bassin en mètres (natation uniquement). */
    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $poolLength;

    /** Jour où la prise de temps a eu lieu. */
    #[ORM\Column(name: 'performed_on', type: 'date_immutable')]
    private \DateTimeImmutable $performedOn;

    #[ORM\Column]
    private int $timeSeconds;

    /** Précisions de l'adhérent (lieu, chronométreur, conditions…). */
    #[ORM\Column(length: 500, nullable: true)]
    private ?string $memberComment;

    #[ORM\Column(length: 16)]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $decidedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $decidedBy = null;

    /** Motif donné par l'entraîneur (surtout en cas de refus). */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $decisionNote = null;

    public function __construct(User $user, PerfTest $test, ?int $poolLength, \DateTimeImmutable $performedOn, int $timeSeconds, ?string $memberComment)
    {
        $this->user = $user;
        $this->test = $test;
        $this->poolLength = $test->needsPoolLength() ? $poolLength : null;
        $this->performedOn = $performedOn;
        $this->timeSeconds = $timeSeconds;
        $this->memberComment = $memberComment !== null && trim($memberComment) !== '' ? trim($memberComment) : null;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getUser(): User { return $this->user; }
    public function getTest(): PerfTest { return $this->test; }
    public function getPoolLength(): ?int { return $this->poolLength; }
    public function getPerformedOn(): \DateTimeImmutable { return $this->performedOn; }
    public function getTimeSeconds(): int { return $this->timeSeconds; }
    public function getMemberComment(): ?string { return $this->memberComment; }
    public function getStatus(): string { return $this->status; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getDecidedAt(): ?\DateTimeImmutable { return $this->decidedAt; }
    public function getDecidedBy(): ?User { return $this->decidedBy; }
    public function getDecisionNote(): ?string { return $this->decisionNote; }

    public function isPending(): bool { return $this->status === self::STATUS_PENDING; }
    public function isAccepted(): bool { return $this->status === self::STATUS_ACCEPTED; }
    public function isRejected(): bool { return $this->status === self::STATUS_REJECTED; }

    /** « 5:42 » ou « 1:02:15 ». */
    public function getTimeLabel(): string
    {
        return PerfTestResult::format($this->timeSeconds);
    }

    /** « 400 m natation — bassin 25 m ». */
    public function getTestLabel(): string
    {
        return $this->test->label().($this->poolLength !== null ? ' — bassin '.$this->poolLength.' m' : '');
    }

    public function accept(User $by, ?string $note = null): self
    {
        return $this->decide(self::STATUS_ACCEPTED, $by, $note);
    }

    public function reject(User $by, ?string $note = null): self
    {
        return $this->decide(self::STATUS_REJECTED, $by, $note);
    }

    private function decide(string $status, User $by, ?string $note): self
    {
        $this->status = $status;
        $this->decidedBy = $by;
        $this->decidedAt = new \DateTimeImmutable();
        $note = $note !== null ? trim($note) : null;
        $this->decisionNote = $note !== null && $note !== '' ? mb_substr($note, 0, 255) : null;
        return $this;
    }
}
