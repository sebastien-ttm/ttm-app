<?php

namespace App\Repository;

use App\Entity\MembershipFee;
use App\Entity\TrainingSeason;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MembershipFee>
 */
class MembershipFeeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MembershipFee::class);
    }

    public function findOneByCriteria(TrainingSeason $season, string $profile, string $typeLicence): ?MembershipFee
    {
        return $this->findOneBy(['season' => $season, 'profile' => $profile, 'typeLicence' => $typeLicence]);
    }

    /** @return list<MembershipFee> */
    public function findBySeason(TrainingSeason $season): array
    {
        return $this->createQueryBuilder('f')
            ->where('f.season = :s')->setParameter('s', $season)
            ->orderBy('f.profile', 'ASC')->addOrderBy('f.typeLicence', 'ASC')
            ->getQuery()->getResult();
    }
}
