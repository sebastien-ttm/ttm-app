<?php

namespace App\Repository;

use App\Entity\Stage;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Stage>
 */
class StageRepository extends ServiceEntityRepository
{
    /** Un stage terminé reste proposé sur l'accueil pendant ce délai (jours), le temps des résultats et du récit. */
    public const RECENT_DAYS = 30;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Stage::class);
    }

    /**
     * Stages publiés auxquels participe l'adhérent, à venir, en cours ou terminés depuis peu, du plus proche au
     * plus lointain.
     *
     * @return list<Stage>
     */
    public function findCurrentForParticipant(User $user, ?\DateTimeImmutable $today = null): array
    {
        $since = ($today ?? new \DateTimeImmutable('today'))->modify('-'.self::RECENT_DAYS.' days');

        /** @var list<Stage> $stages */
        $stages = $this->createQueryBuilder('s')
            ->where('s.published = true')
            ->andWhere(':user MEMBER OF s.participants')
            ->andWhere('COALESCE(s.endsOn, s.startsOn) >= :since')
            ->setParameter('user', $user)
            ->setParameter('since', $since, Types::DATE_IMMUTABLE)
            ->orderBy('s.startsOn', 'ASC')
            ->addOrderBy('s.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $stages;
    }
}
