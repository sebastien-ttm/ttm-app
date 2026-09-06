<?php

namespace App\Repository;

use App\Entity\User;
use App\Entity\UserMessage;
use App\Entity\UserMessageRecipientState;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserMessageRecipientState>
 */
class UserMessageRecipientStateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserMessageRecipientState::class);
    }

    public function findOneByUserAndMessage(User $user, UserMessage $message): ?UserMessageRecipientState
    {
        return $this->findOneBy(['user' => $user, 'message' => $message]);
    }

    /**
     * Renvoie l'état existant OU en instancie un neuf (non persisté).
     * Le caller décide de persist() / flush() selon le geste effectué.
     */
    public function findOrCreate(User $user, UserMessage $message): UserMessageRecipientState
    {
        $state = $this->findOneByUserAndMessage($user, $message);
        return $state ?? new UserMessageRecipientState($user, $message);
    }

    /**
     * Renvoie les états d'un user indexés par message-id, pour joindre
     * l'info « archivé ? » dans la liste inbox sans requête par ligne.
     *
     * @param list<int> $messageIds
     * @return array<int, UserMessageRecipientState>
     */
    public function findByUserIndexedByMessageId(User $user, array $messageIds): array
    {
        if ($messageIds === []) {
            return [];
        }
        $rows = $this->createQueryBuilder('s')
            ->where('s.user = :u')
            ->andWhere('s.message IN (:ids)')
            ->setParameter('u', $user)
            ->setParameter('ids', $messageIds)
            ->getQuery()
            ->getResult();

        $out = [];
        foreach ($rows as $r) {
            $out[$r->getMessage()->getId()] = $r;
        }
        return $out;
    }
}
