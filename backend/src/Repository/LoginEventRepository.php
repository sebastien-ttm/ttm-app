<?php

namespace App\Repository;

use App\Entity\LoginEvent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LoginEvent>
 */
class LoginEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LoginEvent::class);
    }

    /**
     * Nombre total de connexions sur une fenêtre [from, to[.
     */
    public function countLoginsInRange(\DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        return (int) $this->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->where('e.occurredAt >= :from AND e.occurredAt < :to')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Nombre de comptes distincts qui se sont connectés sur la fenêtre
     * [from, to[. Permet « X comptes actifs cette semaine ».
     */
    public function countActiveUsersInRange(\DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        return (int) $this->createQueryBuilder('e')
            ->select('COUNT(DISTINCT IDENTITY(e.user))')
            ->where('e.occurredAt >= :from AND e.occurredAt < :to')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Connexions agrégées par jour sur une fenêtre. Renvoie un tableau
     * indexé par 'Y-m-d' avec le nombre de connexions (tous channels).
     *
     * Les jours sans connexion ne sont PAS dans le résultat — c'est à
     * l'appelant de combler les trous pour le graphique.
     *
     * @return array<string, int>  ['2026-06-04' => 23, '2026-06-05' => 18, ...]
     */
    public function dailyCountsInRange(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            "SELECT DATE(occurred_at) AS d, COUNT(*) AS c
             FROM login_event
             WHERE occurred_at >= :from AND occurred_at < :to
             GROUP BY DATE(occurred_at)
             ORDER BY d ASC",
            [
                'from' => $from->format('Y-m-d H:i:s'),
                'to' => $to->format('Y-m-d H:i:s'),
            ],
        );
        $out = [];
        foreach ($rows as $row) {
            $out[$row['d']] = (int) $row['c'];
        }
        return $out;
    }
}
