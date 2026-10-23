<?php

namespace App\Repository;

use App\Entity\TrainingGroup;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
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

    /**
     * Groupes actifs adossés à une occurrence de créneau : créneau de la semaine type (`$templateId` + la semaine de
     * `$monday`) ou créneau occasionnel (`$slotId`), le plus ancien d'abord.
     *
     * @return list<TrainingGroup>
     */
    public function findActiveOnOccurrence(?int $templateId, ?int $slotId, \DateTimeImmutable $monday, \DateTimeImmutable $now): array
    {
        $qb = $this->createQueryBuilder('g')
            ->where('g.closedAt IS NULL')
            ->andWhere('g.expiresAt > :now')
            ->setParameter('now', $now)
            ->orderBy('g.createdAt', 'ASC')
            ->addOrderBy('g.id', 'ASC');
        if ($templateId !== null) {
            $qb->andWhere('g.slotTemplate = :template')->andWhere('g.weekStartsAt = :week')
                ->setParameter('template', $templateId)
                ->setParameter('week', $monday->modify('monday this week'), Types::DATE_IMMUTABLE);
        } else {
            $qb->andWhere('g.slot = :slot')->setParameter('slot', $slotId);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Tous les groupes actifs adossés à une séance (créneau de la semaine type ou occasionnel), toutes séances
     * confondues : de quoi regrouper par séance sans une requête par séance.
     *
     * @return list<TrainingGroup>
     */
    public function findActiveAttached(\DateTimeImmutable $now): array
    {
        return $this->createQueryBuilder('g')
            ->where('g.closedAt IS NULL')
            ->andWhere('g.expiresAt > :now')
            ->andWhere('g.slotTemplate IS NOT NULL OR g.slot IS NOT NULL')
            ->setParameter('now', $now)
            ->orderBy('g.createdAt', 'ASC')
            ->addOrderBy('g.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** Nombre d'adhérents du groupe, lu en base (fiable sous verrou, contrairement à la collection déjà chargée). */
    public function countMembers(TrainingGroup $group): int
    {
        return (int) $this->getEntityManager()->createQuery(
            'SELECT COUNT(u.id) FROM App\Entity\TrainingGroup g JOIN g.members u WHERE g.id = :id',
        )->setParameter('id', $group->getId())->getSingleScalarResult();
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
