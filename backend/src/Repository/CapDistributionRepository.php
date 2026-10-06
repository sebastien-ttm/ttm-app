<?php

namespace App\Repository;

use App\Entity\CapDistribution;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CapDistribution>
 */
class CapDistributionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CapDistribution::class);
    }

    /**
     * Remises de bonnet groupées par adhérent, de la plus récente à la
     * plus ancienne.
     *
     * @return array<int, list<CapDistribution>> id user => remises
     */
    public function findAllGroupedByUser(): array
    {
        $rows = $this->createQueryBuilder('c')
            ->leftJoin('c.distributedBy', 'b')->addSelect('b')
            ->orderBy('c.distributedAt', 'DESC')
            ->addOrderBy('c.id', 'DESC')
            ->getQuery()
            ->getResult();
        $out = [];
        foreach ($rows as $row) {
            $out[$row->getUser()->getId()][] = $row;
        }
        return $out;
    }

    /** @return list<CapDistribution> remises d'un adhérent, la plus récente d'abord */
    public function findByUser(User $user): array
    {
        return $this->createQueryBuilder('c')
            ->leftJoin('c.distributedBy', 'b')->addSelect('b')
            ->where('c.user = :user')
            ->setParameter('user', $user)
            ->orderBy('c.distributedAt', 'DESC')
            ->addOrderBy('c.id', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
