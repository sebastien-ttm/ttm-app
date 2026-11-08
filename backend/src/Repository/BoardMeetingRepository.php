<?php

namespace App\Repository;

use App\Entity\BoardMeeting;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BoardMeeting>
 */
class BoardMeetingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BoardMeeting::class);
    }

    /**
     * Toutes les réunions : celles à venir d'abord (la plus proche en tête), puis les passées (la plus récente en tête).
     *
     * @return list<BoardMeeting>
     */
    public function findAllForListing(): array
    {
        $all = $this->createQueryBuilder('m')->orderBy('m.scheduledAt', 'DESC')->getQuery()->getResult();
        $today = new \DateTimeImmutable('today');
        $upcoming = array_reverse(array_values(array_filter($all, static fn (BoardMeeting $m) => !$m->isClosed() && $m->getScheduledAt() >= $today)));
        $others = array_values(array_filter($all, static fn (BoardMeeting $m) => $m->isClosed() || $m->getScheduledAt() < $today));
        return array_merge($upcoming, $others);
    }

    /**
     * Prochaine réunion : la plus proche des réunions non closes (y compris une réunion du jour ou à peine passée
     * mais pas encore close, pour la séance en cours). Null s'il n'y en a pas.
     */
    public function findNext(): ?BoardMeeting
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.closedAt IS NULL')
            ->andWhere('m.scheduledAt >= :since')
            ->setParameter('since', new \DateTimeImmutable('-2 days'))
            ->orderBy('m.scheduledAt', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
