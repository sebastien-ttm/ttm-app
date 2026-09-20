<?php

namespace App\Entity;

use App\Repository\TrainingPlanOpenRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Trace un adhérent qui a ouvert le PDF d'un programme d'entraînement
 * — 1 ligne par couple (user, plan). Les ouvertures répétées d'un
 * même user sur le même plan n'ajoutent pas de ligne (INSERT IGNORE
 * via l'index unique), garantissant qu'on compte 1 par adhérent quel
 * que soit le nombre de clics dans la semaine.
 *
 * Utilisée par le tableau d'usage des programmes hebdo côté admin.
 */
#[ORM\Entity(repositoryClass: TrainingPlanOpenRepository::class)]
#[ORM\Table(name: 'training_plan_open')]
#[ORM\UniqueConstraint(name: 'uniq_plan_open_user_plan', columns: ['user_id', 'plan_id'])]
#[ORM\Index(name: 'idx_plan_open_plan', columns: ['plan_id'])]
class TrainingPlanOpen
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne(targetEntity: TrainingPlan::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private TrainingPlan $plan;

    #[ORM\Column]
    private \DateTimeImmutable $openedAt;

    public function __construct(User $user, TrainingPlan $plan, ?\DateTimeImmutable $at = null)
    {
        $this->user = $user;
        $this->plan = $plan;
        $this->openedAt = $at ?? new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getUser(): User { return $this->user; }
    public function getPlan(): TrainingPlan { return $this->plan; }
    public function getOpenedAt(): \DateTimeImmutable { return $this->openedAt; }
}
