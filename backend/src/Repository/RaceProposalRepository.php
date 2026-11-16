<?php

namespace App\Repository;

use App\Entity\RaceProposal;
use App\Entity\User;
use App\Enum\RaceProposalStatus;
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
     * Compétitions à venir ou en cours (dernier jour >= aujourd'hui), la plus proche d'abord, telles que $viewer a le
     * droit de les voir : les publiées (validées ou en pause) de tout le monde, plus ses propres propositions pas encore
     * publiées (en attente, refusées).
     * Les votes sont chargés dans la même requête (compteurs + vote du
     * viewer sans N+1).
     *
     * @return list<RaceProposal>
     */
    public function findUpcomingFor(User $viewer): array
    {
        return $this->createQueryBuilder('r')
            ->leftJoin('r.author', 'a')->addSelect('a')
            ->leftJoin('r.votes', 'v')->addSelect('v')
            ->leftJoin('r.courses', 'c')->addSelect('c')
            ->where('(r.raceDate >= :today OR r.raceEndDate >= :today) AND (r.status IN (:published) OR r.author = :viewer)')
            ->setParameter('today', new \DateTimeImmutable('today'), 'date_immutable')
            ->setParameter('published', RaceProposalStatus::publicValues())
            ->setParameter('viewer', $viewer)
            ->orderBy('r.raceDate', 'ASC')
            ->addOrderBy('r.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** Propositions qui attendent la validation d'un administrateur (pastille du menu du back-office). */
    public function countPending(): int
    {
        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->where('r.status = :pending')
            ->setParameter('pending', RaceProposalStatus::Pending->value)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** Plus grand rang de publication, ou plus grand identifiant s'il est supérieur : de quoi attribuer le rang suivant. */
    public function highestPublicationMark(): int
    {
        $row = $this->createQueryBuilder('r')
            ->select('MAX(r.id) AS maxId', 'MAX(r.publishedRank) AS maxRank')
            ->getQuery()
            ->getSingleResult();

        return max((int) $row['maxId'], (int) $row['maxRank']);
    }
}
