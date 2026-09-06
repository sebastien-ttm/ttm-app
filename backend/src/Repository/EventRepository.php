<?php

namespace App\Repository;

use App\Entity\Event;
use App\Entity\User;
use App\Service\Audience\AudienceFilter;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Event>
 */
class EventRepository extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private readonly AudienceFilter $audienceFilter,
    ) {
        parent::__construct($registry, Event::class);
    }

    /**
     * Événements dont la période [startsAt, endsAt] croise la fenêtre
     * [from, to]. Inclut donc les événements multi-jours en cours
     * (commencés avant `from` mais qui se terminent après).
     *
     * Pour les événements sans endsAt (mono-jour), on considère endsAt
     * implicite = startsAt.
     *
     * @return list<Event>
     */
    public function findInRange(\DateTimeImmutable $from, \DateTimeImmutable $to, ?User $viewer = null): array
    {
        $qb = $this->createQueryBuilder('e')
            // Chevauchement temporel : l'événement commence avant la fin de
            // la fenêtre ET se termine après le début. Si endsAt est null,
            // on retombe sur startsAt (événement ponctuel).
            ->where('e.startsAt <= :to')
            ->andWhere('COALESCE(e.endsAt, e.startsAt) >= :from')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('e.startsAt', 'ASC');

        $this->audienceFilter->apply($qb, $viewer, 'e');
        $this->audienceFilter->applyContentAudienceForDirigeant($qb, $viewer, 'e');

        return $qb->getQuery()->getResult();
    }

    /**
     * Événements soumis au vote (voteEnabled=true) pour le récap admin.
     * On limite aux événements qui ne sont pas trop anciens (fin >= from)
     * pour ne pas afficher tout l'historique. Sans limite haute — les
     * événements futurs très lointains restent visibles.
     *
     * @return list<Event>
     */
    public function findVotable(\DateTimeImmutable $from): array
    {
        return $this->createQueryBuilder('e')
            ->where('e.voteEnabled = true')
            ->andWhere('COALESCE(e.endsAt, e.startsAt) >= :from')
            ->setParameter('from', $from)
            ->orderBy('e.startsAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
