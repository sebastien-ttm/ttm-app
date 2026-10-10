<?php

namespace App\Repository;

use App\Entity\Mailing;
use App\Entity\MailingRecipient;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MailingRecipient>
 */
class MailingRecipientRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MailingRecipient::class);
    }

    /**
     * Nombre de destinataires par statut.
     *
     * @return array<string, int> statut => nombre (les statuts absents valent 0)
     */
    public function countsByStatus(Mailing $mailing): array
    {
        $rows = $this->createQueryBuilder('r')
            ->select('r.status AS status', 'COUNT(r.id) AS n')
            ->where('r.mailing = :m')->setParameter('m', $mailing)
            ->groupBy('r.status')
            ->getQuery()
            ->getScalarResult();

        $counts = [
            MailingRecipient::STATUS_PENDING => 0,
            MailingRecipient::STATUS_SENT => 0,
            MailingRecipient::STATUS_FAILED => 0,
            MailingRecipient::STATUS_SKIPPED => 0,
        ];
        foreach ($rows as $row) {
            $counts[(string) $row['status']] = (int) $row['n'];
        }

        return $counts;
    }

    /**
     * Prochains destinataires à qui envoyer (les plus anciens d'abord).
     *
     * @return list<MailingRecipient>
     */
    public function findPending(Mailing $mailing, int $limit): array
    {
        return $this->createQueryBuilder('r')
            ->addSelect('u')
            ->leftJoin('r.user', 'u')
            ->where('r.mailing = :m')->setParameter('m', $mailing)
            ->andWhere('r.status = :s')->setParameter('s', MailingRecipient::STATUS_PENDING)
            ->orderBy('r.id', 'ASC')
            ->setMaxResults(max(1, $limit))
            ->getQuery()
            ->getResult();
    }

    /**
     * Destinataires d'un mailing pour l'écran d'admin, dans l'ordre alphabétique,
     * éventuellement limités à un statut.
     *
     * @return list<MailingRecipient>
     */
    public function findForAdmin(Mailing $mailing, ?string $status, int $limit): array
    {
        $qb = $this->createQueryBuilder('r')
            ->where('r.mailing = :m')->setParameter('m', $mailing)
            ->orderBy('r.name', 'ASC')
            ->setMaxResults($limit);
        if ($status !== null) {
            $qb->andWhere('r.status = :s')->setParameter('s', $status);
        }

        return $qb->getQuery()->getResult();
    }

    /** Envois réussis depuis la date donnée, tous mailings confondus (quota quotidien). */
    public function countSentSince(\DateTimeImmutable $since): int
    {
        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->where('r.status = :s')->setParameter('s', MailingRecipient::STATUS_SENT)
            ->andWhere('r.sentAt >= :since')->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** Date du dernier envoi réussi d'un mailing (null s'il n'est encore rien parti). */
    public function lastSentAt(Mailing $mailing): ?\DateTimeImmutable
    {
        $value = $this->createQueryBuilder('r')
            ->select('MAX(r.sentAt)')
            ->where('r.mailing = :m')->setParameter('m', $mailing)
            ->getQuery()
            ->getSingleScalarResult();

        return $value !== null ? new \DateTimeImmutable((string) $value) : null;
    }

    /** Remet en attente les envois en échec d'un mailing. Renvoie le nombre de destinataires concernés. */
    public function resetFailed(Mailing $mailing): int
    {
        return (int) $this->createQueryBuilder('r')
            ->update()
            ->set('r.status', ':pending')
            ->set('r.error', 'NULL')
            ->where('r.mailing = :m')
            ->andWhere('r.status = :failed')
            ->setParameter('pending', MailingRecipient::STATUS_PENDING)
            ->setParameter('failed', MailingRecipient::STATUS_FAILED)
            ->setParameter('m', $mailing)
            ->getQuery()
            ->execute();
    }
}
