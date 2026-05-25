<?php

namespace App\Repository;

use App\Entity\MembershipSettings;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MembershipSettings>
 */
class MembershipSettingsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MembershipSettings::class);
    }

    public function findCurrent(): ?MembershipSettings
    {
        return $this->createQueryBuilder('s')
            ->orderBy('s.id', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findOrCreate(): MembershipSettings
    {
        $s = $this->findCurrent();
        if ($s !== null) {
            return $s;
        }
        $s = new MembershipSettings();
        $this->getEntityManager()->persist($s);
        $this->getEntityManager()->flush();
        return $s;
    }
}
