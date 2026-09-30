<?php

namespace App\Entity;

use App\Repository\StaffPresenceTemplateRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Marque qu'un membre du staff (encadrant/entraîneur) est PRÉSENT, en
 * temps normal, sur ce créneau de la semaine type du club — sa « semaine
 * de présence type » personnelle, valable tant que le créneau existe
 * (donc de facto pour la saison du TrainingSlotTemplate visé).
 *
 * Absence de ligne = pas présent habituellement sur ce créneau. Sert de
 * base à StaffPresenceController::applyTemplate(), qui positionne en un
 * clic la présence réelle d'une semaine précise d'après ces marqueurs.
 */
#[ORM\Entity(repositoryClass: StaffPresenceTemplateRepository::class)]
#[ORM\Table(name: 'staff_presence_template')]
#[ORM\UniqueConstraint(name: 'uniq_staff_presence_template_user_slot', columns: ['user_id', 'slot_template_id'])]
#[ORM\Index(name: 'idx_staff_presence_template_user', columns: ['user_id'])]
class StaffPresenceTemplate
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne(targetEntity: TrainingSlotTemplate::class)]
    #[ORM\JoinColumn(name: 'slot_template_id', nullable: false, onDelete: 'CASCADE')]
    private TrainingSlotTemplate $slotTemplate;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(User $user, TrainingSlotTemplate $slotTemplate)
    {
        $this->user = $user;
        $this->slotTemplate = $slotTemplate;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getUser(): User { return $this->user; }
    public function getSlotTemplate(): TrainingSlotTemplate { return $this->slotTemplate; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
