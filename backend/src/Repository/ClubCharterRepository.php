<?php

namespace App\Repository;

use App\Entity\ClubCharter;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ClubCharter>
 */
class ClubCharterRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ClubCharter::class);
    }

    /**
     * Renvoie la charte à appliquer pour un utilisateur donné, dans cet
     * ordre de priorité :
     *   1. Charte en aperçu ciblée sur ce user (preview_user_id = user)
     *      — permet à l'admin de tester avant activation générale.
     *   2. Charte activée pour tous (is_active = true).
     *   3. null (aucune charte à faire signer).
     *
     * Si $user est null (ex : appel non authentifié), fallback direct
     * sur la charte active générale.
     */
    public function findCurrent(?User $user = null): ?ClubCharter
    {
        if ($user !== null) {
            $preview = $this->createQueryBuilder('c')
                ->where('c.previewUser = :user')
                ->setParameter('user', $user)
                ->orderBy('c.publishedAt', 'DESC')
                ->setMaxResults(1)
                ->getQuery()
                ->getOneOrNullResult();
            if ($preview !== null) {
                return $preview;
            }
        }

        return $this->createQueryBuilder('c')
            ->where('c.isActive = true')
            ->orderBy('c.publishedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Sets all other charters' isActive to false.
     */
    public function deactivateAllExcept(?int $exceptId = null): void
    {
        $qb = $this->createQueryBuilder('c')
            ->update()
            ->set('c.isActive', ':false')
            ->setParameter('false', false);
        if ($exceptId !== null) {
            $qb->where('c.id != :id')->setParameter('id', $exceptId);
        }
        $qb->getQuery()->execute();
    }
}
