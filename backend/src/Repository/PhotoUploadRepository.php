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

    /**
     * Nombre de photos envoyées depuis l'appli, par album Piwigo.
     *
     * @return array<int, int> id album => nombre
     */
    public function countByAlbum(): array
    {
        $rows = $this->createQueryBuilder('p')
            ->select('p.piwigoAlbumId AS albumId, COUNT(p.id) AS n')
            ->groupBy('p.piwigoAlbumId')
            ->getQuery()
            ->getArrayResult();
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['albumId']] = (int) $row['n'];
        }
        return $out;
    }

    /**
     * Photos envoyées depuis l'appli dans un album Piwigo, les plus anciennes d'abord.
     *
     * @return list<PhotoUpload>
     */
    public function findByAlbum(int $albumId): array
    {
        return $this->createQueryBuilder('p')
            ->leftJoin('p.user', 'u')->addSelect('u')
            ->where('p.piwigoAlbumId = :album')
            ->setParameter('album', $albumId)
            ->orderBy('p.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** Supprime les traces d'envoi d'un album (après sa suppression dans Piwigo). */
    public function deleteByAlbum(int $albumId): int
    {
        return (int) $this->createQueryBuilder('p')
            ->delete()
            ->where('p.piwigoAlbumId = :album')
            ->setParameter('album', $albumId)
            ->getQuery()
            ->execute();
    }

    /** Photos que cet adhérent a envoyées dans cet album, ou que le staff y a publiées en son nom. */
    public function countByUserInAlbum(User $user, int $albumId): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->where('p.user = :user OR p.uploadedBy = :user')
            ->andWhere('p.piwigoAlbumId = :album')
            ->setParameter('user', $user)
            ->setParameter('album', $albumId)
            ->getQuery()
            ->getSingleScalarResult();
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
