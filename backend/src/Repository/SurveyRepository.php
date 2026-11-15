<?php

namespace App\Repository;

use App\Entity\Survey;
use App\Entity\User;
use App\Service\Audience\AudienceFilter;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Survey>
 */
class SurveyRepository extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private readonly AudienceFilter $audienceFilter,
    ) {
        parent::__construct($registry, Survey::class);
    }

    /**
     * Sondages actuellement ouverts (publiés, non-fermés) et applicables
     * à ce user (via audience). Ordre plus récent d'abord.
     *
     * @return list<Survey>
     */
    public function findOpenFor(User $user): array
    {
        $now = new \DateTimeImmutable();
        $qb = $this->createQueryBuilder('s')
            ->andWhere('s.publishedAt IS NOT NULL')
            ->andWhere('s.publishedAt <= :now')
            ->andWhere('s.closesAt IS NULL OR s.closesAt >= :now')
            // Un sondage dédié à un stage n'apparaît que dans la vue du stage (ni liste, ni compteur « non répondus »).
            ->andWhere('s.stage IS NULL')
            ->setParameter('now', $now->format('Y-m-d H:i:s'))
            ->orderBy('s.publishedAt', 'DESC');

        $this->audienceFilter->apply($qb, $user, 's');

        return $qb->getQuery()->getResult();
    }

    /**
     * Sondages que ce user essaie avant leur publication : il en est testeur et ils ne sont pas (encore) publiés
     * (brouillon ou publication programmée). Tous types confondus, y compris dédiés à un stage. Plus récent d'abord.
     *
     * @return list<Survey>
     */
    public function findTestFor(User $user): array
    {
        return $this->createQueryBuilder('s')
            ->innerJoin('s.testers', 't')
            ->andWhere('t = :user')
            ->andWhere('s.publishedAt IS NULL OR s.publishedAt > :now')
            ->setParameter('user', $user)
            ->setParameter('now', (new \DateTimeImmutable())->format('Y-m-d H:i:s'))
            ->orderBy('s.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /** Total des sondages publiés (tous statuts confondus, pour l'admin). */
    public function findAllForAdmin(): array
    {
        return $this->createQueryBuilder('s')
            ->orderBy('s.publishedAt', 'DESC')
            ->addOrderBy('s.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
