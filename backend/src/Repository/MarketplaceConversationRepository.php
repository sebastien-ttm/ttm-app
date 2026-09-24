<?php

namespace App\Repository;

use App\Entity\MarketplaceConversation;
use App\Entity\MarketplaceListing;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MarketplaceConversation>
 */
class MarketplaceConversationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MarketplaceConversation::class);
    }

    public function findOneByListingAndBuyer(MarketplaceListing $listing, User $buyer): ?MarketplaceConversation
    {
        return $this->findOneBy(['listing' => $listing, 'buyer' => $buyer]);
    }

    /**
     * Toutes les discussions d'un user, qu'il soit acheteur ou vendeur,
     * la plus récemment active d'abord.
     *
     * @return list<MarketplaceConversation>
     */
    public function findForUser(User $user): array
    {
        return $this->createQueryBuilder('c')
            ->innerJoin('c.listing', 'l')->addSelect('l')
            ->innerJoin('l.author', 'seller')->addSelect('seller')
            ->innerJoin('c.buyer', 'buyer')->addSelect('buyer')
            ->leftJoin('l.photos', 'p')->addSelect('p')
            ->where('c.buyer = :user OR l.author = :user')
            ->setParameter('user', $user)
            ->orderBy('c.lastMessageAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
