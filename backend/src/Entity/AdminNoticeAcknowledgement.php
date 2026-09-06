<?php

namespace App\Entity;

use App\Repository\AdminNoticeAcknowledgementRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Trace l'acquittement (« J'ai compris ») d'un user sur une AdminNotice.
 * Une ligne par couple (user, notice) — unique. L'absence de ligne =
 * pas encore vu / pas encore validé, la notice s'affiche encore.
 */
#[ORM\Entity(repositoryClass: AdminNoticeAcknowledgementRepository::class)]
#[ORM\Table(name: 'admin_notice_acknowledgement')]
#[ORM\UniqueConstraint(name: 'uniq_notice_ack', columns: ['user_id', 'notice_id'])]
#[ORM\Index(name: 'idx_notice_ack_user', columns: ['user_id'])]
#[ORM\Index(name: 'idx_notice_ack_notice', columns: ['notice_id'])]
class AdminNoticeAcknowledgement
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne(targetEntity: AdminNotice::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private AdminNotice $notice;

    #[ORM\Column]
    private \DateTimeImmutable $acknowledgedAt;

    public function __construct(User $user, AdminNotice $notice)
    {
        $this->user = $user;
        $this->notice = $notice;
        $this->acknowledgedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getUser(): User { return $this->user; }
    public function getNotice(): AdminNotice { return $this->notice; }
    public function getAcknowledgedAt(): \DateTimeImmutable { return $this->acknowledgedAt; }
}
