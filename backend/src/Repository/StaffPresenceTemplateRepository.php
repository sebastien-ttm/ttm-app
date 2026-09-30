<?php

namespace App\Repository;

use App\Entity\StaffPresenceTemplate;
use App\Entity\TrainingSlotTemplate;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<StaffPresenceTemplate>
 */
class StaffPresenceTemplateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StaffPresenceTemplate::class);
    }

    public function findOneByUserAndSlotTemplate(User $user, TrainingSlotTemplate $tpl): ?StaffPresenceTemplate
    {
        return $this->findOneBy(['user' => $user, 'slotTemplate' => $tpl]);
    }

    /**
     * Ids des TrainingSlotTemplate marqués « présent » par ce user.
     *
     * @return array<int, true>
     */
    public function findPresentTemplateIds(User $user): array
    {
        $rows = $this->createQueryBuilder('t')
            ->select('IDENTITY(t.slotTemplate) AS tid')
            ->where('t.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getScalarResult();
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['tid']] = true;
        }
        return $out;
    }
}
