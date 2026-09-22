<?php

namespace App\Repository;

use App\Entity\Event;
use App\Entity\MemberGroup;
use App\Entity\TrainingSeason;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MemberGroup>
 */
class MemberGroupRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MemberGroup::class);
    }

    public function findOneBySeasonAndName(TrainingSeason $season, string $name): ?MemberGroup
    {
        return $this->findOneBy(['season' => $season, 'name' => $name]);
    }

    public function findOneByEvent(Event $event): ?MemberGroup
    {
        return $this->findOneBy(['sourceEvent' => $event]);
    }
}
