<?php

namespace App\Repository;

use App\Entity\BoardAction;
use App\Entity\BoardMeeting;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BoardAction>
 */
class BoardActionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BoardAction::class);
    }

    /**
     * Toutes les actions en cours (non clôturées), toutes réunions confondues : échéance la plus proche d'abord,
     * celles sans échéance à la fin.
     *
     * @return list<BoardAction>
     */
    public function findOpen(): array
    {
        return self::sortByDueDate($this->baseQuery()->andWhere('a.closedAt IS NULL')->getQuery()->getResult());
    }

    /**
     * Les dernières actions clôturées (« faites »), la plus récemment clôturée d'abord, toutes réunions confondues.
     *
     * @return list<BoardAction>
     */
    public function findRecentlyClosed(int $limit = 30): array
    {
        // Deux temps : la jointure sur les porteurs multiplie les lignes, et un LIMIT s'y appliquerait aux lignes (pas aux actions).
        $rows = $this->createQueryBuilder('a')
            ->select('a.id')
            ->andWhere('a.closedAt IS NOT NULL')
            ->orderBy('a.closedAt', 'DESC')
            ->addOrderBy('a.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getArrayResult();
        $ids = array_map(static fn (array $r) => (int) $r['id'], $rows);
        if ($ids === []) {
            return [];
        }

        /** @var list<BoardAction> $actions */
        $actions = $this->baseQuery()->andWhere('a.id IN (:ids)')->setParameter('ids', $ids)->getQuery()->getResult();
        usort($actions, static fn (BoardAction $a, BoardAction $b) => [$b->getClosedAt(), $b->getId()] <=> [$a->getClosedAt(), $a->getId()]);

        return $actions;
    }

    /**
     * Actions en cours au moment d'une réunion, pour son compte rendu : celles décidées lors de cette réunion ou
     * des précédentes, et pas encore clôturées — ou clôturées seulement après la clôture de la réunion (si elle est
     * close ; sinon, à ce jour).
     *
     * @return list<BoardAction>
     */
    public function findOpenAsOf(BoardMeeting $meeting): array
    {
        $qb = $this->baseQuery()
            ->andWhere('(m.scheduledAt <= :scheduled OR m.id = :meetingId)')
            ->andWhere('(a.closedAt IS NULL OR a.closedAt > :reference)')
            ->setParameter('scheduled', $meeting->getScheduledAt())
            ->setParameter('meetingId', $meeting->getId())
            ->setParameter('reference', $meeting->getClosedAt() ?? new \DateTimeImmutable());

        return self::sortByDueDate($qb->getQuery()->getResult());
    }

    private function baseQuery(): QueryBuilder
    {
        return $this->createQueryBuilder('a')
            ->innerJoin('a.item', 'i')->addSelect('i')
            ->innerJoin('i.meeting', 'm')->addSelect('m')
            ->leftJoin('a.assignees', 'u')->addSelect('u');
    }

    /**
     * Échéance la plus proche d'abord (les sans-échéance à la fin), puis ordre de la réunion, puis de création.
     *
     * @param list<BoardAction> $actions
     * @return list<BoardAction>
     */
    private static function sortByDueDate(array $actions): array
    {
        usort($actions, static fn (BoardAction $a, BoardAction $b) => [
            $a->getDueDate() === null ? 1 : 0,
            $a->getDueDate()?->format('Y-m-d') ?? '',
            $a->getItem()->getMeeting()->getScheduledAt()->format('Y-m-d H:i'),
            $a->getId(),
        ] <=> [
            $b->getDueDate() === null ? 1 : 0,
            $b->getDueDate()?->format('Y-m-d') ?? '',
            $b->getItem()->getMeeting()->getScheduledAt()->format('Y-m-d H:i'),
            $b->getId(),
        ]);

        return $actions;
    }
}
