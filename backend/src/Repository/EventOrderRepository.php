<?php

namespace App\Repository;

use App\Entity\Event;
use App\Entity\EventOrder;
use App\Entity\EventOrderLine;
use App\Entity\User;
use App\Enum\EventOrderStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EventOrder>
 */
class EventOrderRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EventOrder::class);
    }

    /**
     * Places déjà prises par tarif pour un événement : quantités des commandes payées et des commandes en attente dont la
     * réservation court encore. Clé = identifiant du tarif.
     *
     * @return array<int, int>
     */
    public function takenByTariff(Event $event, ?\DateTimeImmutable $now = null): array
    {
        $rows = $this->getEntityManager()->createQueryBuilder()
            ->select('IDENTITY(l.tariff) AS tariffId', 'SUM(l.quantity) AS qty')
            ->from(EventOrderLine::class, 'l')
            ->join('l.eventOrder', 'o')
            ->where('o.event = :event')
            ->andWhere('l.tariff IS NOT NULL')
            ->andWhere('(o.status = :paid OR (o.status = :pending AND o.expiresAt > :now))')
            ->setParameter('event', $event)
            ->setParameter('paid', EventOrderStatus::Paid->value)
            ->setParameter('pending', EventOrderStatus::Pending->value)
            ->setParameter('now', $now ?? new \DateTimeImmutable(), 'datetime_immutable')
            ->groupBy('l.tariff')
            ->getQuery()
            ->getArrayResult();

        $taken = [];
        foreach ($rows as $row) {
            $taken[(int) $row['tariffId']] = (int) $row['qty'];
        }

        return $taken;
    }

    /**
     * Commandes d'un adhérent pour un événement, la plus récente d'abord, sans celles qu'il a annulées ou laissées
     * s'abandonner (elles n'ont aucun intérêt pour lui).
     *
     * @return list<EventOrder>
     */
    public function findActiveForUser(Event $event, User $user): array
    {
        return $this->createQueryBuilder('o')
            ->leftJoin('o.lines', 'l')->addSelect('l')
            ->where('o.event = :event AND o.user = :user AND o.status IN (:statuses)')
            ->setParameter('event', $event)
            ->setParameter('user', $user)
            ->setParameter('statuses', [EventOrderStatus::Paid->value, EventOrderStatus::Pending->value])
            ->orderBy('o.createdAt', 'DESC')
            ->addOrderBy('o.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findOneByCheckoutIntentId(int $intentId): ?EventOrder
    {
        return $this->findOneBy(['checkoutIntentId' => $intentId]);
    }
}
