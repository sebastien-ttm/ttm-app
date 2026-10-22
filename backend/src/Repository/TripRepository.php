<?php

namespace App\Repository;

use App\Entity\Trip;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Trip>
 */
class TripRepository extends ServiceEntityRepository
{
    /** Un déplacement terminé reste proposé sur l'accueil pendant ce délai (jours), le temps des photos et du récit. */
    public const RECENT_DAYS = 30;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Trip::class);
    }

    /**
     * Déplacements publiés auxquels participe l'adhérent, à venir, en cours ou terminés depuis peu, du plus proche
     * au plus lointain.
     *
     * @return list<Trip>
     */
    public function findCurrentForParticipant(User $user, ?\DateTimeImmutable $today = null): array
    {
        $since = ($today ?? new \DateTimeImmutable('today'))->modify('-'.self::RECENT_DAYS.' days');

        /** @var list<Trip> $trips */
        $trips = $this->createQueryBuilder('t')
            ->where('t.published = true')
            ->andWhere(':user MEMBER OF t.participants')
            ->andWhere('COALESCE(t.endsOn, t.startsOn) >= :since')
            ->setParameter('user', $user)
            ->setParameter('since', $since, Types::DATE_IMMUTABLE)
            ->orderBy('t.startsOn', 'ASC')
            ->addOrderBy('t.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $trips;
    }
}
