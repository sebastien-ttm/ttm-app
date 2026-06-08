<?php

namespace App\Repository;

use App\Entity\StaffPresence;
use App\Entity\TrainingSlot;
use App\Entity\User;
use App\Enum\Profile;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<StaffPresence>
 */
class StaffPresenceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StaffPresence::class);
    }

    /** Toutes les présences d'un user pour une semaine donnée. */
    public function findByUserAndWeek(User $user, \DateTimeImmutable $weekStartsAt): array
    {
        $monday = $weekStartsAt->modify('monday this week')->setTime(0, 0, 0);
        return $this->createQueryBuilder('p')
            ->leftJoin('p.slot', 's')->addSelect('s')
            ->where('p.user = :user')
            ->andWhere('p.weekStartsAt = :w')
            ->setParameter('user', $user)
            ->setParameter('w', $monday->format('Y-m-d'))
            ->orderBy('p.date', 'ASC')
            ->addOrderBy('p.startTime', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findOneByUserAndSlot(User $user, TrainingSlot $slot): ?StaffPresence
    {
        return $this->findOneBy(['user' => $user, 'slot' => $slot]);
    }

    /**
     * Combien d'utilisateurs se sont positionnés sur ce créneau ?
     * Utilisé par « Restaurer le modèle » pour décider entre suppression
     * franche (0 présence) et soft reset (préserve les présences).
     */
    public function countForSlot(TrainingSlot $slot): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->where('p.slot = :s')
            ->setParameter('s', $slot)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Toutes les présences d'une semaine pour TOUS les staff (encadrants
     * et entraîneurs), groupées par user_id.
     *
     * @return array<int, list<StaffPresence>>
     */
    public function findStaffPresencesForWeekGroupedByUser(\DateTimeImmutable $weekStartsAt): array
    {
        $monday = $weekStartsAt->modify('monday this week')->setTime(0, 0, 0);
        $rows = $this->createQueryBuilder('p')
            ->leftJoin('p.slot', 's')->addSelect('s')
            ->leftJoin('p.user', 'u')->addSelect('u')
            ->where('p.weekStartsAt = :w')
            ->setParameter('w', $monday->format('Y-m-d'))
            ->orderBy('p.date', 'ASC')
            ->addOrderBy('p.startTime', 'ASC')
            ->getQuery()
            ->getResult();

        $byUser = [];
        foreach ($rows as $r) {
            $uid = $r->getUser()->getId();
            $byUser[$uid] ??= [];
            $byUser[$uid][] = $r;
        }
        return $byUser;
    }

    /**
     * Retourne les users actifs qui ont un profile staff donné.
     *
     * @return list<User>
     */
    public function findActiveStaffByProfile(Profile $profile): array
    {
        $em = $this->getEntityManager();
        return $em->createQueryBuilder()
            ->select('u')
            ->from(User::class, 'u')
            ->where('u.isActive = true')
            ->andWhere("JSON_CONTAINS(u.profiles, :p) = 1")
            ->setParameter('p', json_encode($profile->value))
            ->orderBy('u.nom', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
