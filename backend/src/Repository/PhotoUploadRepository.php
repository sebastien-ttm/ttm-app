<?php

namespace App\Repository;

use App\Entity\PhotoUpload;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PhotoUpload>
 */
class PhotoUploadRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PhotoUpload::class);
    }

    public function findOneByPiwigoImageId(int $imageId): ?PhotoUpload
    {
        return $this->findOneBy(['piwigoImageId' => $imageId]);
    }

    /**
     * Envois connus pour ces images Piwigo, indexés par id Piwigo
     * (auteur affiché sous chaque photo d'un album).
     *
     * @param list<int> $imageIds
     * @return array<int, PhotoUpload>
     */
    public function findIndexedByPiwigoImageIds(array $imageIds): array
    {
        if ($imageIds === []) {
            return [];
        }
        $rows = $this->createQueryBuilder('p')
            ->leftJoin('p.user', 'u')->addSelect('u')
            ->where('p.piwigoImageId IN (:ids)')
            ->setParameter('ids', $imageIds)
            ->getQuery()
            ->getResult();
        $out = [];
        foreach ($rows as $row) {
            $out[$row->getPiwigoImageId()] = $row;
        }
        return $out;
    }

    public function countByUserSince(User $user, \DateTimeImmutable $since): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->where('p.user = :user')
            ->andWhere('p.createdAt >= :since')
            ->setParameter('user', $user)
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
