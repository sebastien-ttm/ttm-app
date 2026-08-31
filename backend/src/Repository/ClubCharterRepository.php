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
     * Renvoie le formulaire d'acceptation à appliquer pour un utilisateur
     * donné :
     *   1. Aperçu privé ciblé sur ce user (previewUser = user) — permet
     *      à l'admin de tester avant de publier pour tout le monde.
     *   2. Le plus récent en date de publication (publishedAt DESC).
     *   3. null (aucun formulaire à faire signer).
     *
     * Un seul formulaire actif à la fois — plus de distinction par
     * type d'adhérent (nouveau vs renouvellement). La page CRUD permet
     * quand même de conserver l'historique par saison.
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
            ->orderBy('c.publishedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
