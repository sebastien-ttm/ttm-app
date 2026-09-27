<?php

namespace App\Repository;

use App\Entity\BibOffer;
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

    public function findOneByBibOfferAndBuyer(BibOffer $offer, User $buyer): ?MarketplaceConversation
    {
        return $this->findOneBy(['bibOffer' => $offer, 'buyer' => $buyer]);
    }

    /**
     * Toutes les discussions d'un user, qu'il soit acheteur ou vendeur,
     * annonces comme dossards, la plus récemment active d'abord.
     *
     * @return list<MarketplaceConversation>
     */
    public function findForUser(User $user): array
    {
        return $this->createQueryBuilder('c')
            ->leftJoin('c.listing', 'l')->addSelect('l')
            ->leftJoin('l.author', 'seller')->addSelect('seller')
            ->leftJoin('l.photos', 'p')->addSelect('p')
            ->leftJoin('c.bibOffer', 'b')->addSelect('b')
            ->leftJoin('b.author', 'bibSeller')->addSelect('bibSeller')
            ->innerJoin('c.buyer', 'buyer')->addSelect('buyer')
            ->where('c.buyer = :user OR l.author = :user OR b.author = :user')
            ->setParameter('user', $user)
            ->orderBy('c.lastMessageAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
