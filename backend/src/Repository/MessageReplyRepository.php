<?php

namespace App\Repository;

use App\Entity\MessageReply;
use App\Entity\UserMessage;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MessageReply>
 */
class MessageReplyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MessageReply::class);
    }

    /**
     * @return list<MessageReply>
     */
    public function findAllByMessage(UserMessage $message): array
    {
        return $this->createQueryBuilder('r')
            ->leftJoin('r.author', 'a')->addSelect('a')
            ->where('r.message = :message')
            ->setParameter('message', $message)
            ->orderBy('r.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
