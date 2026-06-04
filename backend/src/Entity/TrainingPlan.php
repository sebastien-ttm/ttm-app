<?php

namespace App\Entity;

use App\Entity\Trait\AudienceAwareTrait;
use App\Enum\TrainingPlanCategory;
use App\Repository\TrainingPlanRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Validator\Constraints as Assert;
use Vich\UploaderBundle\Mapping\Annotation as Vich;

#[ORM\Entity(repositoryClass: TrainingPlanRepository::class)]
#[ORM\Table(name: 'training_plan')]
#[ORM\HasLifecycleCallbacks]
#[Vich\Uploadable]
class TrainingPlan
{
    use AudienceAwareTrait;

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
    private ?string $filePath = null;

    #[Vich\UploadableField(mapping: 'training_files', fileNameProperty: 'filePath')]
    #[Assert\File(maxSize: '10M', mimeTypes: ['application/pdf'], mimeTypesMessage: 'Le fichier doit être un PDF.')]
    private ?File $file = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $postedBy;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $weekStartsAt = null;

    #[ORM\Column(length: 24, enumType: TrainingPlanCategory::class, options: ['default' => 'general'])]
    private TrainingPlanCategory $category = TrainingPlanCategory::General;

    #[ORM\Column]
    private \DateTimeImmutable $postedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    /**
     * Horodate l'envoi des emails de notification.
     * NULL = pas encore envoyés. Garantit l'idempotence en cas de retry
     * de la queue Messenger ou de re-publication.
     */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $emailsSentAt = null;

    public function __construct()
    {
        $this->postedAt = new \DateTimeImmutable();
    }

    /**
     * Ensures weekStartsAt is always stored as the Monday of the ISO week,
     * regardless of how it was set.
     */
    #[ORM\PrePersist]
    #[ORM\PreUpdate]
    public function snapWeekStartsAtToMonday(): void
    {
        if ($this->weekStartsAt === null) {
            return;
        }
        $monday = $this->weekStartsAt->modify('monday this week')->setTime(0, 0, 0);
        if ($monday->format('Y-m-d') !== $this->weekStartsAt->format('Y-m-d')) {
            $this->weekStartsAt = $monday;
        }
    }

    public function getId(): ?int { return $this->id; }
    public function getTitle(): string { return $this->title; }
    public function setTitle(string $title): self { $this->title = $title; return $this; }
    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): self { $this->description = $description; return $this; }
    public function getFilePath(): ?string { return $this->filePath; }
    public function setFilePath(?string $filePath): self { $this->filePath = $filePath; return $this; }

    public function getFile(): ?File { return $this->file; }

    public function setFile(?File $file): self
    {
        $this->file = $file;
        if ($file !== null) {
            $this->updatedAt = new \DateTimeImmutable();
        }
        return $this;
    }

    public function getPostedBy(): User { return $this->postedBy; }
    public function setPostedBy(User $postedBy): self { $this->postedBy = $postedBy; return $this; }
    public function getWeekStartsAt(): ?\DateTimeImmutable { return $this->weekStartsAt; }
    public function setWeekStartsAt(?\DateTimeImmutable $d): self { $this->weekStartsAt = $d; return $this; }

    public function getCategory(): TrainingPlanCategory { return $this->category; }
    public function setCategory(TrainingPlanCategory $c): self { $this->category = $c; return $this; }

    /**
     * Title with public-facing suffix appended (only for non-default categories).
     * E.g. "Plan semaine 19 (longue distance)" when category = LongueDistance,
     * or just "Plan semaine 19" when category = General.
     */
    public function getDisplayTitle(): string
    {
        $suffix = $this->category->publicSuffix();
        return $suffix === '' ? $this->title : trim($this->title.' '.$suffix);
    }
    public function getPostedAt(): \DateTimeImmutable { return $this->postedAt; }
    public function setPostedAt(\DateTimeImmutable $d): self { $this->postedAt = $d; return $this; }

    /**
     * ISO 8601 week string in the format "YYYY-Www" (e.g. "2026-W19"),
     * compatible with HTML5 <input type="week">.
     */
    public function getIsoWeek(): ?string
    {
        return $this->weekStartsAt?->format('o-\WW');
    }

    /**
     * Sets weekStartsAt to the Monday of the given ISO week.
     * Accepts the format "YYYY-Www" or null/empty string to clear.
     */
    public function setIsoWeek(?string $iso): self
    {
        if ($iso === null || $iso === '') {
            $this->weekStartsAt = null;
            return $this;
        }
        if (!preg_match('/^(\d{4})-W(\d{1,2})$/', $iso, $m)) {
            throw new \InvalidArgumentException(sprintf('Format ISO week invalide : "%s" (attendu : YYYY-Www).', $iso));
        }
        $year = (int) $m[1];
        $week = (int) $m[2];
        if ($week < 1 || $week > 53) {
            throw new \InvalidArgumentException(sprintf('Numéro de semaine invalide : %d (attendu 1-53).', $week));
        }
        $monday = (new \DateTimeImmutable())->setISODate($year, $week, 1)->setTime(0, 0, 0);
        $this->weekStartsAt = $monday;
        return $this;
    }

    /**
     * Human-readable week range, e.g. "Semaine du lundi 4 au dimanche 10 mai 2026".
     */
    public function getWeekRangeLabel(): ?string
    {
        if ($this->weekStartsAt === null) {
            return null;
        }
        // Defensive: snap to the Monday of the same ISO week, in case the stored
        // value is anything else (legacy data, manual edit, etc.).
        $start = $this->weekStartsAt->modify('monday this week');
        $end = $start->modify('+6 days');

        $fmtStart = new \IntlDateFormatter(
            'fr_FR',
            \IntlDateFormatter::LONG,
            \IntlDateFormatter::NONE,
            null,
            null,
            'EEEE d MMMM',
        );
        $fmtEnd = new \IntlDateFormatter(
            'fr_FR',
            \IntlDateFormatter::LONG,
            \IntlDateFormatter::NONE,
            null,
            null,
            'EEEE d MMMM y',
        );

        return sprintf(
            'Semaine du %s au %s',
            (string) $fmtStart->format($start),
            (string) $fmtEnd->format($end),
        );
    }

    public function getEmailsSentAt(): ?\DateTimeImmutable { return $this->emailsSentAt; }
    public function setEmailsSentAt(?\DateTimeImmutable $d): self { $this->emailsSentAt = $d; return $this; }

    public function __toString(): string { return $this->title ?? '#'.$this->id; }
}
