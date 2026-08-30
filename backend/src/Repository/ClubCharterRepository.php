<?php

namespace App\Repository;

use App\Entity\ClubCharter;
use App\Entity\User;
use App\Enum\AdherentKind;
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
     * Renvoie le formulaire d'acceptation à appliquer pour un utilisateur
     * donné :
     *   1. Aperçu privé ciblé sur ce user (previewUser = user) — permet
     *      à l'admin de tester avant de publier pour tout le monde.
     *   2. Le plus récent en date de publication (publishedAt DESC).
     *   3. null (aucun formulaire à faire signer).
     *
     * Si $user est null (ex : appel non authentifié), fallback direct
     * sur le plus récent.
     */
    public function findCurrent(?User $user = null, ?AdherentKind $kind = null): ?ClubCharter
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

        // Priorité : formulaire dédié au kind exact (new/renewal), sinon
        // fallback sur le formulaire `all`. Dans les deux cas on prend le
        // plus récent en date de publication.
        if ($kind !== null && $kind !== AdherentKind::All) {
            $exact = $this->createQueryBuilder('c')
                ->where('c.kind = :k')
                ->setParameter('k', $kind)
                ->orderBy('c.publishedAt', 'DESC')
                ->setMaxResults(1)
                ->getQuery()
                ->getOneOrNullResult();
            if ($exact !== null) {
                return $exact;
            }
        }

        return $this->createQueryBuilder('c')
            ->where('c.kind = :k')
            ->setParameter('k', AdherentKind::All)
            ->orderBy('c.publishedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
