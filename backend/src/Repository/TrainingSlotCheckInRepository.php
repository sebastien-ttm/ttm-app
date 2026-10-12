<?php

namespace App\Repository;

use App\Entity\TrainingSlotCheckIn;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Émargements des entraînements sur inscription. Une occurrence se désigne comme pour les inscriptions : créneau de
 * la semaine type (`$templateId` + semaine) ou créneau occasionnel (`$slotId`).
 *
 * @extends ServiceEntityRepository<TrainingSlotCheckIn>
 */
class TrainingSlotCheckInRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TrainingSlotCheckIn::class);
    }

    public function findOneByOccurrenceAndUser(?int $templateId, ?int $slotId, \DateTimeImmutable $monday, User $user): ?TrainingSlotCheckIn
    {
        $qb = $this->createQueryBuilder('c')->andWhere('c.user = :user')->setParameter('user', $user)->setMaxResults(1);
        $this->filterOccurrence($qb, $templateId, $slotId, $monday);

        return $qb->getQuery()->getOneOrNullResult();
    }

    /**
     * Émargements d'une occurrence, indexés par identifiant d'adhérent (utilisateurs chargés).
     *
     * @return array<int, TrainingSlotCheckIn>
     */
    public function findByOccurrenceIndexedByUser(?int $templateId, ?int $slotId, \DateTimeImmutable $monday): array
    {
        $qb = $this->createQueryBuilder('c')->addSelect('u', 'by')->join('c.user', 'u')->leftJoin('c.checkedBy', 'by');
        $this->filterOccurrence($qb, $templateId, $slotId, $monday);

        $out = [];
        foreach ($qb->getQuery()->getResult() as $checkIn) {
            $out[(int) $checkIn->getUser()->getId()] = $checkIn;
        }

        return $out;
    }

    /**
     * Nombre d'émargés par occurrence sur un ensemble de semaines (lundis) : clés « t12|2026-10-12 » (semaine type) et
     * « s5 » (occasionnel) — voir `key()`.
     *
     * @param list<\DateTimeImmutable> $mondays
     *
     * @return array<string, int>
     */
    public function countsForWeeks(array $mondays): array
    {
        if ($mondays === []) {
            return [];
        }
        $rows = $this->createQueryBuilder('c')
            ->select('IDENTITY(c.template) AS templateId', 'IDENTITY(c.slot) AS slotId', 'c.weekStartsAt AS week', 'COUNT(c.id) AS n')
            ->where('c.weekStartsAt IN (:weeks)')
            ->setParameter('weeks', array_map(static fn (\DateTimeImmutable $m) => $m->format('Y-m-d'), $mondays))
            ->groupBy('c.template', 'c.slot', 'c.weekStartsAt')
            ->getQuery()
            ->getArrayResult();

        $out = [];
        foreach ($rows as $r) {
            $week = $r['week'] instanceof \DateTimeInterface ? $r['week']->format('Y-m-d') : (string) $r['week'];
            $out[self::key($r['templateId'] !== null ? (int) $r['templateId'] : null, $r['slotId'] !== null ? (int) $r['slotId'] : null, $week)] = (int) $r['n'];
        }

        return $out;
    }

    /** Clé d'une occurrence pour countsForWeeks : « t12|2026-10-12 » ou « s5 ». */
    public static function key(?int $templateId, ?int $slotId, string $monday): string
    {
        return $templateId !== null ? 't'.$templateId.'|'.$monday : 's'.$slotId;
    }

    private function filterOccurrence(QueryBuilder $qb, ?int $templateId, ?int $slotId, \DateTimeImmutable $monday): void
    {
        if ($templateId !== null) {
            $qb->andWhere('c.template = :template')->andWhere('c.weekStartsAt = :w')
                ->setParameter('template', $templateId)
                ->setParameter('w', $monday->modify('monday this week')->setTime(0, 0, 0)->format('Y-m-d'));
        } else {
            $qb->andWhere('c.slot = :slot')->setParameter('slot', (int) $slotId);
        }
    }
}
