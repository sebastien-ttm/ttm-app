<?php

namespace App\Repository;

use App\Entity\AdminNotice;
use App\Entity\AdminNoticeAcknowledgement;
use App\Entity\User;
use App\Service\Audience\AudienceFilter;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AdminNotice>
 */
class AdminNoticeRepository extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private readonly AudienceFilter $audienceFilter,
    ) {
        parent::__construct($registry, AdminNotice::class);
    }

    /**
     * Notices actuellement affichables pour ce user :
     *  - publiées (publishedAt <= now)
     *  - non-expirées (expiresAt IS NULL OR expiresAt >= now)
     *  - non-acquittées par le viewer
     *  - dans son audience (audience vide OU intersection avec ses profils)
     *
     * Ordre chronologique (plus vieille publiée d'abord) pour un
     * affichage FIFO côté mobile.
     *
     * @return list<AdminNotice>
     */
    public function findPendingFor(User $user): array
    {
        $now = new \DateTimeImmutable();
        $qb = $this->createQueryBuilder('n')
            ->andWhere('n.publishedAt IS NOT NULL')
            ->andWhere('n.publishedAt <= :now')
            ->andWhere('n.expiresAt IS NULL OR n.expiresAt >= :now')
            ->andWhere('NOT EXISTS (
                SELECT 1 FROM '.AdminNoticeAcknowledgement::class.' ack
                 WHERE ack.notice = n AND ack.user = :viewer
            )')
            ->setParameter('now', $now->format('Y-m-d H:i:s'))
            ->setParameter('viewer', $user)
            ->orderBy('n.publishedAt', 'ASC');

        $this->audienceFilter->apply($qb, $user, 'n');

        /** @var list<AdminNotice> $rows */
        $rows = $qb->getQuery()->getResult();
        return $rows;
    }

    /**
     * Count des acquittements pour une notice — pour affichage admin.
     */
    public function countAcknowledgementsFor(AdminNotice $notice): int
    {
        return (int) $this->getEntityManager()->createQueryBuilder()
            ->select('COUNT(a.id)')
            ->from(AdminNoticeAcknowledgement::class, 'a')
            ->where('a.notice = :n')->setParameter('n', $notice)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
