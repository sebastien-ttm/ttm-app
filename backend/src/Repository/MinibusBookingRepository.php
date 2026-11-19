<?php

namespace App\Repository;

use App\Entity\MinibusBooking;
use App\Entity\User;
use App\Enum\MinibusBookingStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MinibusBooking>
 */
class MinibusBookingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MinibusBooking::class);
    }

    /**
     * Ce que montre le calendrier à partir d'une date : les demandes en attente et validées qui se terminent à cette date
     * ou après, plus — pour retrouver son historique — les demandes refusées ou annulées du demandeur sur la même période.
     *
     * @return list<MinibusBooking> par date de début, puis de demande
     */
    public function findForCalendar(\DateTimeImmutable $from, User $viewer): array
    {
        return $this->createQueryBuilder('b')
            ->where('b.endDate >= :from')
            ->andWhere('b.status IN (:live) OR b.requester = :viewer')
            ->setParameter('from', $from->setTime(0, 0))
            ->setParameter('live', array_map(static fn (MinibusBookingStatus $s) => $s->value, [MinibusBookingStatus::Pending, MinibusBookingStatus::Approved]))
            ->setParameter('viewer', $viewer)
            ->orderBy('b.startDate', 'ASC')
            ->addOrderBy('b.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Autres demandes en attente ou validées qui chevauchent la période (bornes comprises).
     *
     * @param list<MinibusBookingStatus> $statuses
     *
     * @return list<MinibusBooking>
     */
    public function findOverlapping(\DateTimeImmutable $start, \DateTimeImmutable $end, array $statuses, ?MinibusBooking $except = null): array
    {
        $qb = $this->createQueryBuilder('b')
            ->where('b.startDate <= :end')
            ->andWhere('b.endDate >= :start')
            ->andWhere('b.status IN (:statuses)')
            ->setParameter('start', $start->setTime(0, 0))
            ->setParameter('end', $end->setTime(0, 0))
            ->setParameter('statuses', array_map(static fn (MinibusBookingStatus $s) => $s->value, $statuses))
            ->orderBy('b.startDate', 'ASC');
        if ($except?->getId() !== null) {
            $qb->andWhere('b.id != :except')->setParameter('except', $except->getId());
        }

        return $qb->getQuery()->getResult();
    }

    /** Nombre de demandes en attente de la décision du gestionnaire (pastille de l'appli et du back-office). */
    public function countPending(): int
    {
        return (int) $this->createQueryBuilder('b')
            ->select('COUNT(b.id)')
            ->where('b.status = :pending')
            ->setParameter('pending', MinibusBookingStatus::Pending->value)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Toutes les demandes pour le back-office : en attente d'abord, puis par date de début décroissante.
     *
     * @return list<MinibusBooking>
     */
    public function findAllForAdmin(): array
    {
        $all = $this->createQueryBuilder('b')
            ->orderBy('b.startDate', 'DESC')
            ->addOrderBy('b.id', 'DESC')
            ->getQuery()
            ->getResult();
        $pending = array_values(array_filter($all, static fn (MinibusBooking $b) => $b->isPending()));
        $others = array_values(array_filter($all, static fn (MinibusBooking $b) => !$b->isPending()));
        // Les demandes en attente se traitent dans l'ordre des dates : la plus proche en tête.
        usort($pending, static fn (MinibusBooking $a, MinibusBooking $b) => $a->getStartDate() <=> $b->getStartDate());

        return array_merge($pending, $others);
    }
}
