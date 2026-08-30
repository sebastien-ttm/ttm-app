<?php

namespace App\Repository;

use App\Entity\TrainingSeason;
use App\Entity\User;
use App\Entity\UserSeasonMembership;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserSeasonMembership>
 */
class UserSeasonMembershipRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserSeasonMembership::class);
    }

    public function findOneByUserAndSeason(User $user, TrainingSeason $season): ?UserSeasonMembership
    {
        return $this->findOneBy(['user' => $user, 'season' => $season]);
    }

    /** @return list<UserSeasonMembership> */
    public function findBySeason(TrainingSeason $season): array
    {
        return $this->createQueryBuilder('m')
            ->leftJoin('m.user', 'u')->addSelect('u')
            ->where('m.season = :s')->setParameter('s', $season)
            ->orderBy('u.nom', 'ASC')->addOrderBy('u.prenom', 'ASC')
            ->getQuery()->getResult();
    }

    public function countForSeason(TrainingSeason $season): int
    {
        return (int) $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->where('m.season = :s')->setParameter('s', $season)
            ->getQuery()->getSingleScalarResult();
    }

    /**
     * Nombre total d'adhésions (toutes saisons confondues) pour un user.
     * >1 signifie que l'user a au moins une adhésion passée en plus de
     * la saison courante — traité comme un « renouvellement ».
     */
    public function countForUser(User $user): int
    {
        return (int) $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->where('m.user = :u')->setParameter('u', $user)
            ->getQuery()->getSingleScalarResult();
    }

    /**
     * Prochain numéro d'ordre libre pour les factures de la saison :
     * MAX(invoice_sequence) + 1, ou 1 si aucun.
     *
     * Simple (pas de verrouillage) : suffisant pour un club où les
     * factures sont générées ponctuellement par un seul admin.
     */
    public function nextInvoiceSequence(TrainingSeason $season): int
    {
        $max = $this->createQueryBuilder('m')
            ->select('MAX(m.invoiceSequence)')
            ->where('m.season = :s')->setParameter('s', $season)
            ->getQuery()->getSingleScalarResult();
        return ((int) $max) + 1;
    }
}
