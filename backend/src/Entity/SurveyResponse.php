<?php

namespace App\Entity;

use App\Repository\SurveyResponseRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Réponse d'un adhérent à un sondage. Une seule ligne par (user,
 * survey) — l'user peut modifier sa réponse tant que le sondage est
 * ouvert (updatedAt reflète la dernière soumission).
 */
#[ORM\Entity(repositoryClass: SurveyResponseRepository::class)]
#[ORM\Table(name: 'survey_response')]
#[ORM\UniqueConstraint(name: 'uniq_survey_response_user', columns: ['user_id', 'survey_id'])]
#[ORM\Index(name: 'idx_survey_response_survey', columns: ['survey_id'])]
class SurveyResponse
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne(targetEntity: Survey::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Survey $survey;

    /**
     * Réponses indexées par id de question. Les valeurs sont typées
     * selon le type de question :
     *  - short_text / long_text    → string
     *  - single_choice             → string (option choisie)
     *  - multi_choice              → list<string> (options cochées)
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: 'json')]
    private array $answers = [];

    #[ORM\Column]
    private \DateTimeImmutable $submittedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct(User $user, Survey $survey)
    {
        $this->user = $user;
        $this->survey = $survey;
        $this->submittedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getUser(): User { return $this->user; }
    public function getSurvey(): Survey { return $this->survey; }

    /** @return array<string, mixed> */
    public function getAnswers(): array { return $this->answers; }

    /** @param array<string, mixed> $a */
    public function setAnswers(array $a): self
    {
        $this->answers = $a;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function getSubmittedAt(): \DateTimeImmutable { return $this->submittedAt; }
    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }
}
