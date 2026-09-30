<?php

namespace App\Repository;

use App\Entity\Event;
use App\Entity\EventCarpoolOffer;
use App\Entity\RaceProposal;
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

    public function findOneByUserAndSubject(User $user, Event|RaceProposal $subject): ?EventCarpoolOffer
    {
        return $subject instanceof Event
            ? $this->findOneBy(['user' => $user, 'event' => $subject])
            : $this->findOneBy(['user' => $user, 'raceProposal' => $subject]);
    }

    /**
     * Toutes les propositions pour un sujet (événement ou proposition de
     * course) — conducteurs + passagers, triées par date de création ASC.
     *
     * @return list<EventCarpoolOffer>
     */
    public function findBySubject(Event|RaceProposal $subject): array
    {
        $field = $subject instanceof Event ? 'event' : 'raceProposal';
        return $this->createQueryBuilder('o')
            ->leftJoin('o.user', 'u')->addSelect('u')
            ->where("o.{$field} = :subject")->setParameter('subject', $subject)
            ->orderBy('o.role', 'ASC')
            ->addOrderBy('o.createdAt', 'ASC')
            ->getQuery()->getResult();
    }
}
