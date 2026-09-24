<?php

namespace App\Repository;

use App\Entity\MarketplaceConversation;
use App\Entity\MarketplaceMessage;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MarketplaceMessage>
 */
class MarketplaceMessageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MarketplaceMessage::class);
    }

    /**
     * Dernier message de chaque discussion, indexé par id de conversation —
     * pour l'aperçu de la liste, sans charger tous les messages.
     *
     * @param list<MarketplaceConversation> $conversations
     * @return array<int, MarketplaceMessage>
     */
    public function findLastByConversations(array $conversations): array
    {
        if ($conversations === []) {
            return [];
        }
        /** @var list<MarketplaceMessage> $rows */
        $rows = $this->createQueryBuilder('m')
            ->innerJoin('m.author', 'a')->addSelect('a')
            ->where('m.id IN (
                SELECT MAX(m2.id) FROM '.MarketplaceMessage::class.' m2
                WHERE m2.conversation IN (:convs)
                GROUP BY m2.conversation
            )')
            ->setParameter('convs', array_map(static fn (MarketplaceConversation $c) => $c->getId(), $conversations))
            ->getQuery()
            ->getResult();

        $out = [];
        foreach ($rows as $m) {
            $out[$m->getConversation()->getId()] = $m;
        }
        return $out;
    }

    /** Anti-spam : messages postés par ce user depuis $since. */
    public function countByAuthorSince(User $author, \DateTimeImmutable $since): int
    {
        return (int) $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->where('m.author = :author')
            ->andWhere('m.createdAt >= :since')
            ->setParameter('author', $author)
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
