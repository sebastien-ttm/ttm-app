<?php

namespace App\Repository;

use App\Entity\TrainingGroup;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TrainingGroup>
 */
class TrainingGroupRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TrainingGroup::class);
    }

    /**
     * Groupes actifs (ni terminés, ni expirés) où l'utilisateur est encadrant ou adhérent, le plus récent d'abord.
     *
     * @return list<TrainingGroup>
     */
    public function findActiveFor(User $user, \DateTimeImmutable $now): array
    {
        return $this->createQueryBuilder('g')
            ->where('g.closedAt IS NULL')
            ->andWhere('g.expiresAt > :now')
            ->andWhere('(:u MEMBER OF g.coaches OR :u MEMBER OF g.members)')
            ->setParameter('now', $now)
            ->setParameter('u', $user)
            ->orderBy('g.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /** Le groupe actif que cet utilisateur encadre (un encadrant n'en a qu'un à la fois), s'il y en a un. */
    public function findActiveCoachedBy(User $user, \DateTimeImmutable $now): ?TrainingGroup
    {
        return $this->createQueryBuilder('g')
            ->where('g.closedAt IS NULL')
            ->andWhere('g.expiresAt > :now')
            ->andWhere(':u MEMBER OF g.coaches')
            ->setParameter('now', $now)
            ->setParameter('u', $user)
            ->orderBy('g.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Groupes terminés ou expirés avant cette date (nettoyage des fichiers et des données).
     *
     * @return list<TrainingGroup>
     */
    public function findFinishedBefore(\DateTimeImmutable $limit): array
    {
        return $this->createQueryBuilder('g')
            ->where('g.expiresAt < :l')
            ->orWhere('g.closedAt IS NOT NULL AND g.closedAt < :l')
            ->setParameter('l', $limit)
            ->getQuery()
            ->getResult();
    }
}
