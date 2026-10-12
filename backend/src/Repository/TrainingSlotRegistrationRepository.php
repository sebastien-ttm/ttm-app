<?php

namespace App\Repository;

use App\Entity\TrainingSlotRegistration;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Les inscriptions d'adhérents inactifs ne comptent ni dans les places prises ni dans les listes.
 *
 * @extends ServiceEntityRepository<TrainingSlotRegistration>
 */
class TrainingSlotRegistrationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TrainingSlotRegistration::class);
    }

    /**
     * Les inscriptions de l'adhérent sur la semaine, tous créneaux confondus (une, ou deux si la semaine est
     * débloquée), dans l'ordre de leur rang.
     *
     * @return list<TrainingSlotRegistration>
     */
    public function findByUserAndWeek(User $user, \DateTimeImmutable $monday): array
    {
        return $this->findBy(['user' => $user, 'weekStartsAt' => $this->monday($monday)], ['position' => 'ASC']);
    }

    /**
     * Toutes les inscriptions (actives) de la semaine, sous forme légère : sert à compter les places prises et à
     * retrouver l'inscription du viewer pour chaque créneau de la semaine, en une seule requête.
     *
     * @return list<array{userId: int, templateId: ?int, slotId: ?int}>
     */
    public function findKeysForWeek(\DateTimeImmutable $monday): array
    {
        $rows = $this->createQueryBuilder('r')
            ->select('IDENTITY(r.user) AS userId', 'IDENTITY(r.template) AS templateId', 'IDENTITY(r.slot) AS slotId')
            ->join('r.user', 'u')
            ->where('r.weekStartsAt = :w')
            ->andWhere('u.isActive = true')
            ->setParameter('w', $this->monday($monday)->format('Y-m-d'))
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $r): array => [
            'userId' => (int) $r['userId'],
            'templateId' => $r['templateId'] !== null ? (int) $r['templateId'] : null,
            'slotId' => $r['slotId'] !== null ? (int) $r['slotId'] : null,
        ], $rows);
    }

    /** Places prises sur une occurrence : créneau de la semaine type (`$templateId`) ou occasionnel (`$slotId`). */
    public function countForOccurrence(?int $templateId, ?int $slotId, \DateTimeImmutable $monday): int
    {
        $qb = $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->join('r.user', 'u')
            ->andWhere('u.isActive = true');
        $this->filterOccurrence($qb, $templateId, $slotId, $monday);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Inscrits d'une occurrence, dans l'ordre d'inscription (utilisateurs chargés).
     *
     * @return list<TrainingSlotRegistration>
     */
    public function findForOccurrence(?int $templateId, ?int $slotId, \DateTimeImmutable $monday): array
    {
        $qb = $this->createQueryBuilder('r')
            ->addSelect('u')
            ->join('r.user', 'u')
            ->andWhere('u.isActive = true')
            ->orderBy('r.createdAt', 'ASC')
            ->addOrderBy('r.id', 'ASC');
        $this->filterOccurrence($qb, $templateId, $slotId, $monday);

        return $qb->getQuery()->getResult();
    }

    /**
     * Toutes les inscriptions (actives) de la semaine avec leur adhérent, dans l'ordre d'inscription.
     *
     * @return list<TrainingSlotRegistration>
     */
    public function findForWeekWithUsers(\DateTimeImmutable $monday): array
    {
        return $this->createQueryBuilder('r')
            ->addSelect('u')
            ->join('r.user', 'u')
            ->where('r.weekStartsAt = :w')
            ->andWhere('u.isActive = true')
            ->setParameter('w', $this->monday($monday)->format('Y-m-d'))
            ->orderBy('r.createdAt', 'ASC')
            ->addOrderBy('r.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    private function filterOccurrence(QueryBuilder $qb, ?int $templateId, ?int $slotId, \DateTimeImmutable $monday): void
    {
        if ($templateId !== null) {
            $qb->andWhere('r.template = :template')->andWhere('r.weekStartsAt = :w')
                ->setParameter('template', $templateId)
                ->setParameter('w', $this->monday($monday)->format('Y-m-d'));
        } else {
            $qb->andWhere('r.slot = :slot')->setParameter('slot', (int) $slotId);
        }
    }

    private function monday(\DateTimeImmutable $d): \DateTimeImmutable
    {
        return $d->modify('monday this week')->setTime(0, 0, 0);
    }
}
