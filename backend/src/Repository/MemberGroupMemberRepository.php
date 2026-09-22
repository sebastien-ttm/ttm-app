<?php

namespace App\Repository;

use App\Entity\MemberGroup;
use App\Entity\MemberGroupMember;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MemberGroupMember>
 */
class MemberGroupMemberRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MemberGroupMember::class);
    }

    public function findOneByGroupAndUser(MemberGroup $group, User $user): ?MemberGroupMember
    {
        return $this->findOneBy(['group' => $group, 'user' => $user]);
    }
}
