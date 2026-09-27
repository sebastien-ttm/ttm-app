<?php

namespace App\Repository;

use App\Entity\RaceProposal;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RaceProposal>
 */
class RaceProposalRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RaceProposal::class);
    }

    /**
     * Courses à venir (date >= aujourd'hui), la plus proche d'abord.
     * Les votes sont chargés dans la même requête (compteurs + vote du
     * viewer sans N+1).
     *
     * @return list<RaceProposal>
     */
    public function findUpcoming(): array
    {
        return $this->createQueryBuilder('r')
            ->leftJoin('r.author', 'a')->addSelect('a')
            ->leftJoin('r.votes', 'v')->addSelect('v')
            ->where('r.raceDate >= :today')
            ->setParameter('today', new \DateTimeImmutable('today'), 'date_immutable')
            ->orderBy('r.raceDate', 'ASC')
            ->addOrderBy('r.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
