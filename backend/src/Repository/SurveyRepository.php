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
            ->setParameter('now', $now->format('Y-m-d H:i:s'))
            ->orderBy('s.publishedAt', 'DESC');

        $this->audienceFilter->apply($qb, $user, 's');

        return $qb->getQuery()->getResult();
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
