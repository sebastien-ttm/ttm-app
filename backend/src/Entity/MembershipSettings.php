<?php

namespace App\Entity;

use App\Repository\MembershipSettingsRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Réglages liés à la gestion des adhésions (singleton).
 * Conserve une seule ligne en base ; le repo gère le findOrCreate().
 */
#[ORM\Entity(repositoryClass: MembershipSettingsRepository::class)]
#[ORM\Table(name: 'membership_settings')]
class MembershipSettings
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * Tant qu'on est avant cette date, l'import CSV ne désactive PAS les
     * adhérents absents du fichier — les anciens ont le temps de renouveler
     * leur licence. Après, comportement normal (désactivation des absents).
     */
    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $oldMembersValidUntil = null;

    public function getId(): ?int { return $this->id; }

    public function getOldMembersValidUntil(): ?\DateTimeImmutable { return $this->oldMembersValidUntil; }
    public function setOldMembersValidUntil(?\DateTimeImmutable $d): self { $this->oldMembersValidUntil = $d; return $this; }

    /**
     * Renvoie true si on est encore dans la période de grâce
     * (les anciens adhérents non encore renouvelés restent actifs).
     */
    public function isInOldMembersGracePeriod(?\DateTimeImmutable $now = null): bool
    {
        if ($this->oldMembersValidUntil === null) {
            return false;
        }
        $now ??= new \DateTimeImmutable('today');
        return $now <= $this->oldMembersValidUntil;
    }
}
