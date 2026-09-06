<?php

namespace App\Repository;

use App\Entity\AdminNotice;
use App\Entity\AdminNoticeAcknowledgement;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AdminNoticeAcknowledgement>
 */
class AdminNoticeAcknowledgementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AdminNoticeAcknowledgement::class);
    }

    public function findOneByUserAndNotice(User $user, AdminNotice $notice): ?AdminNoticeAcknowledgement
    {
        return $this->findOneBy(['user' => $user, 'notice' => $notice]);
    }
}
