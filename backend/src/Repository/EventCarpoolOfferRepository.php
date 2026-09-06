<?php

namespace App\Repository;

use App\Entity\Event;
use App\Entity\EventCarpoolOffer;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EventCarpoolOffer>
 */
class EventCarpoolOfferRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EventCarpoolOffer::class);
    }

    public function findOneByUserAndEvent(User $user, Event $event): ?EventCarpoolOffer
    {
        return $this->findOneBy(['user' => $user, 'event' => $event]);
    }

    /**
     * Toutes les propositions pour un événement (conducteurs +
     * passagers), triées par date de création ASC.
     *
     * @return list<EventCarpoolOffer>
     */
    public function findByEvent(Event $event): array
    {
        return $this->createQueryBuilder('o')
            ->leftJoin('o.user', 'u')->addSelect('u')
            ->where('o.event = :e')->setParameter('e', $event)
            ->orderBy('o.role', 'ASC')
            ->addOrderBy('o.createdAt', 'ASC')
            ->getQuery()->getResult();
    }
}
