<?php

namespace App\Entity;

use App\Repository\SurveyDismissalRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Sondage que l'adhérent a écarté (« pas concerné ») sans y répondre :
 * il apparaît coché dans sa liste et n'alimente plus le compteur de
 * sondages non répondus. Une seule ligne par (user, survey) ; la ligne
 * se supprime pour annuler, ou automatiquement quand l'adhérent répond.
 */
#[ORM\Entity(repositoryClass: SurveyDismissalRepository::class)]
#[ORM\Table(name: 'survey_dismissal')]
#[ORM\UniqueConstraint(name: 'uniq_survey_dismissal_user', columns: ['user_id', 'survey_id'])]
#[ORM\Index(name: 'idx_survey_dismissal_survey', columns: ['survey_id'])]
class SurveyDismissal
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

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(User $user, Survey $survey)
    {
        $this->user = $user;
        $this->survey = $survey;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getUser(): User { return $this->user; }
    public function getSurvey(): Survey { return $this->survey; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
