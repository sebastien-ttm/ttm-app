<?php

namespace App\Repository;

use App\Entity\MarketplaceListing;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MarketplaceListing>
 */
class MarketplaceListingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MarketplaceListing::class);
    }

    /**
     * Annonces publiées (pausedAt IS NULL), plus récentes d'abord —
     * liste « Annonces » visible par tout le club.
     *
     * @return list<MarketplaceListing>
     */
    public function findPublished(): array
    {
        return $this->createQueryBuilder('l')
            ->leftJoin('l.author', 'a')->addSelect('a')
            ->leftJoin('l.photos', 'p')->addSelect('p')
            ->where('l.pausedAt IS NULL')
            ->orderBy('l.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Toutes les annonces d'un auteur (publiées ET en pause), plus
     * récentes d'abord — liste « Mes annonces ».
     *
     * @return list<MarketplaceListing>
     */
    public function findAllByAuthor(User $author): array
    {
        return $this->createQueryBuilder('l')
            ->leftJoin('l.photos', 'p')->addSelect('p')
            ->where('l.author = :author')
            ->setParameter('author', $author)
            ->orderBy('l.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
