<?php

namespace App\Repository;

use App\Entity\MarketplaceListing;
use App\Entity\MarketplaceListingLike;
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
     * Annonces publiées (ni en pause, ni expirées : mises en ligne ou réactivées depuis moins de
     * MarketplaceListing::LIFETIME_DAYS jours), plus récentes d'abord — liste « Annonces » visible par tout le club.
     *
     * @return list<MarketplaceListing>
     */
    public function findPublished(): array
    {
        $cutoff = (new \DateTimeImmutable())->modify('-'.MarketplaceListing::LIFETIME_DAYS.' days');

        return $this->createQueryBuilder('l')
            ->leftJoin('l.author', 'a')->addSelect('a')
            ->leftJoin('l.photos', 'p')->addSelect('p')
            ->where('l.pausedAt IS NULL')
            ->andWhere('l.publishedAt > :cutoff')
            ->setParameter('cutoff', $cutoff)
            ->orderBy('l.publishedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Annonces que cet adhérent aime, quel que soit leur état (publiées, expirées ou en pause), plus récentes d'abord :
     * il les garde dans sa liste tant que leur auteur ne les a pas supprimées.
     *
     * @return list<MarketplaceListing>
     */
    public function findLikedBy(User $user): array
    {
        return $this->createQueryBuilder('l')
            ->innerJoin(MarketplaceListingLike::class, 'k', 'WITH', 'k.listing = l')
            ->leftJoin('l.author', 'a')->addSelect('a')
            ->leftJoin('l.photos', 'p')->addSelect('p')
            ->where('k.user = :user')
            ->setParameter('user', $user)
            ->orderBy('l.publishedAt', 'DESC')
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
