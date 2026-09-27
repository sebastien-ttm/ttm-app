<?php

namespace App\Repository;

use App\Entity\RaceProposal;
use App\Entity\RaceProposalVote;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RaceProposalVote>
 */
class RaceProposalVoteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RaceProposalVote::class);
    }

    public function findOneByProposalAndUser(RaceProposal $proposal, User $user): ?RaceProposalVote
    {
        return $this->findOneBy(['proposal' => $proposal, 'user' => $user]);
    }
}
