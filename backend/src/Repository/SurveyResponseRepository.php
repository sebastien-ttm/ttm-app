<?php

namespace App\Repository;

use App\Entity\Survey;
use App\Entity\SurveyResponse;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SurveyResponse>
 */
class SurveyResponseRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SurveyResponse::class);
    }

    public function findOneByUserAndSurvey(User $user, Survey $survey): ?SurveyResponse
    {
        return $this->findOneBy(['user' => $user, 'survey' => $survey]);
    }

    public function countForSurvey(Survey $survey): int
    {
        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->where('r.survey = :s')->setParameter('s', $survey)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Nombre de réponses par sondage, en une requête (liste mobile).
     *
     * @param list<int> $surveyIds
     * @return array<int, int> id sondage => nombre de réponses
     */
    public function countBySurveyIds(array $surveyIds): array
    {
        if ($surveyIds === []) return [];
        $rows = $this->createQueryBuilder('r')
            ->select('IDENTITY(r.survey) AS sid', 'COUNT(r.id) AS n')
            ->where('r.survey IN (:ids)')->setParameter('ids', $surveyIds)
            ->groupBy('r.survey')
            ->getQuery()
            ->getScalarResult();
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['sid']] = (int) $r['n'];
        }
        return $out;
    }

    /**
     * @return list<SurveyResponse>
     */
    public function findBySurveyWithUser(Survey $survey): array
    {
        return $this->createQueryBuilder('r')
            ->leftJoin('r.user', 'u')->addSelect('u')
            ->where('r.survey = :s')->setParameter('s', $survey)
            ->orderBy('r.submittedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Ids des sondages auxquels ce user a déjà répondu — pour marquer
     * la liste côté mobile sans requête par sondage.
     *
     * @param list<int> $surveyIds
     * @return array<int, true>
     */
    public function findAnsweredSurveyIds(User $user, array $surveyIds): array
    {
        if ($surveyIds === []) return [];
        $rows = $this->createQueryBuilder('r')
            ->select('IDENTITY(r.survey) AS sid')
            ->where('r.user = :u')->setParameter('u', $user)
            ->andWhere('r.survey IN (:ids)')->setParameter('ids', $surveyIds)
            ->getQuery()
            ->getScalarResult();
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['sid']] = true;
        }
        return $out;
    }
}
