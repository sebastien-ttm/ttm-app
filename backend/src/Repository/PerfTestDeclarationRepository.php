<?php

namespace App\Repository;

use App\Entity\PerfTestDeclaration;
use App\Entity\User;
use App\Enum\PerfTest;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PerfTestDeclaration>
 */
class PerfTestDeclarationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PerfTestDeclaration::class);
    }

    /**
     * Écran backend : demandes d'un statut (null = toutes). Les demandes en
     * attente sont triées de la plus ancienne à la plus récente (ordre de
     * traitement), les autres de la plus récente à la plus ancienne.
     *
     * @return list<PerfTestDeclaration>
     */
    public function findForAdmin(?string $status, int $limit): array
    {
        $qb = $this->createQueryBuilder('d')
            ->innerJoin('d.user', 'u')->addSelect('u')
            ->leftJoin('d.decidedBy', 'decider')->addSelect('decider')
            ->setMaxResults($limit);
        if ($status !== null) {
            $qb->where('d.status = :status')->setParameter('status', $status);
        }
        $qb->orderBy('d.createdAt', $status === PerfTestDeclaration::STATUS_PENDING ? 'ASC' : 'DESC')
            ->addOrderBy('d.id', $status === PerfTestDeclaration::STATUS_PENDING ? 'ASC' : 'DESC');

        return $qb->getQuery()->getResult();
    }

    /** Nombre de demandes en attente (badge du menu backend). */
    public function countPending(): int
    {
        return (int) $this->createQueryBuilder('d')
            ->select('COUNT(d.id)')
            ->where('d.status = :s')->setParameter('s', PerfTestDeclaration::STATUS_PENDING)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @return array<string, int> statut => nombre de demandes */
    public function countsByStatus(): array
    {
        $rows = $this->createQueryBuilder('d')
            ->select('d.status AS status', 'COUNT(d.id) AS n')
            ->groupBy('d.status')
            ->getQuery()
            ->getScalarResult();
        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['status']] = (int) $row['n'];
        }
        return $out;
    }

    /**
     * Mes demandes, la plus récente d'abord.
     *
     * @return list<PerfTestDeclaration>
     */
    public function findByUser(User $user, int $limit = 50): array
    {
        return $this->createQueryBuilder('d')
            ->where('d.user = :u')->setParameter('u', $user)
            ->orderBy('d.createdAt', 'DESC')
            ->addOrderBy('d.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countPendingByUser(User $user): int
    {
        return (int) $this->createQueryBuilder('d')
            ->select('COUNT(d.id)')
            ->where('d.user = :u')->setParameter('u', $user)
            ->andWhere('d.status = :s')->setParameter('s', PerfTestDeclaration::STATUS_PENDING)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Même demande déjà faite (en attente ou acceptée) : même adhérent,
     * épreuve, bassin, jour et temps — évite les doublons d'un double appui.
     */
    public function findDuplicate(User $user, PerfTest $test, ?int $poolLength, \DateTimeImmutable $performedOn, int $timeSeconds): ?PerfTestDeclaration
    {
        $qb = $this->createQueryBuilder('d')
            ->where('d.user = :u')->setParameter('u', $user)
            ->andWhere('d.test = :t')->setParameter('t', $test)
            ->andWhere('d.performedOn = :on')->setParameter('on', $performedOn->format('Y-m-d'))
            ->andWhere('d.timeSeconds = :sec')->setParameter('sec', $timeSeconds)
            ->andWhere('d.status IN (:st)')->setParameter('st', [PerfTestDeclaration::STATUS_PENDING, PerfTestDeclaration::STATUS_ACCEPTED])
            ->setMaxResults(1);
        if ($poolLength === null) {
            $qb->andWhere('d.poolLength IS NULL');
        } else {
            $qb->andWhere('d.poolLength = :pool')->setParameter('pool', $poolLength);
        }

        return $qb->getQuery()->getOneOrNullResult();
    }
}
