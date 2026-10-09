<?php

namespace App\Repository;

use App\Entity\Survey;
use App\Entity\SurveyDismissal;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SurveyDismissal>
 */
class SurveyDismissalRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SurveyDismissal::class);
    }

    public function findOneByUserAndSurvey(User $user, Survey $survey): ?SurveyDismissal
    {
        return $this->findOneBy(['user' => $user, 'survey' => $survey]);
    }

    /**
     * Ids des sondages que ce user a écartés — pour marquer la liste
     * côté mobile et exclure ces sondages du compteur sans requête par
     * sondage.
     *
     * @param list<int> $surveyIds
     * @return array<int, true>
     */
    public function findDismissedSurveyIds(User $user, array $surveyIds): array
    {
        if ($surveyIds === []) return [];
        $rows = $this->createQueryBuilder('d')
            ->select('IDENTITY(d.survey) AS sid')
            ->where('d.user = :u')->setParameter('u', $user)
            ->andWhere('d.survey IN (:ids)')->setParameter('ids', $surveyIds)
            ->getQuery()
            ->getScalarResult();
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['sid']] = true;
        }
        return $out;
    }
}
