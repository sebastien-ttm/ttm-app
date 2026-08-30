<?php

namespace App\Repository;

use App\Entity\WelcomeEmailTemplate;
use App\Enum\AdherentKind;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WelcomeEmailTemplate>
 */
class WelcomeEmailTemplateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WelcomeEmailTemplate::class);
    }

    /**
     * Renvoie le template applicable pour un kind donné :
     *  1) template dédié au kind exact (new ou renewal)
     *  2) fallback sur le template `all` (par défaut, rétro-compat)
     *  3) null si rien de défini
     */
    public function findForKind(AdherentKind $kind): ?WelcomeEmailTemplate
    {
        if ($kind !== AdherentKind::All) {
            $exact = $this->findOneBy(['kind' => $kind]);
            if ($exact !== null) {
                return $exact;
            }
        }
        return $this->findOneBy(['kind' => AdherentKind::All]);
    }

    /**
     * @deprecated utilisez findForKind(). Conservé pour compat existante.
     */
    public function findCurrent(): ?WelcomeEmailTemplate
    {
        return $this->findForKind(AdherentKind::All);
    }
}
