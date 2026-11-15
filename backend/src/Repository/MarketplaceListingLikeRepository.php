<?php

namespace App\Repository;

use App\Entity\MarketplaceListing;
use App\Entity\MarketplaceListingLike;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MarketplaceListingLike>
 */
class MarketplaceListingLikeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MarketplaceListingLike::class);
    }

    public function findOneByListingAndUser(MarketplaceListing $listing, User $user): ?MarketplaceListingLike
    {
        return $this->findOneBy(['listing' => $listing, 'user' => $user]);
    }

    /**
     * Identifiants des annonces que cet adhérent aime.
     *
     * @return list<int>
     */
    public function findListingIdsByUser(User $user): array
    {
        $rows = $this->createQueryBuilder('k')
            ->select('IDENTITY(k.listing) AS id')
            ->where('k.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getScalarResult();

        return array_map(static fn (array $r) => (int) $r['id'], $rows);
    }
}
