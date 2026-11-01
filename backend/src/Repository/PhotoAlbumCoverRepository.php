<?php

namespace App\Repository;

use App\Entity\PhotoAlbumCover;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PhotoAlbumCover>
 */
class PhotoAlbumCoverRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PhotoAlbumCover::class);
    }

    public function findOneByAlbum(int $albumId): ?PhotoAlbumCover
    {
        return $this->findOneBy(['piwigoAlbumId' => $albumId]);
    }

    /**
     * Couvertures choisies, indexées par id d'album Piwigo.
     *
     * @return array<int, PhotoAlbumCover>
     */
    public function indexedByAlbum(): array
    {
        $out = [];
        foreach ($this->findAll() as $cover) {
            $out[$cover->getPiwigoAlbumId()] = $cover;
        }

        return $out;
    }

    /** Une photo supprimée ne peut plus servir de couverture : l'album reprend la photo proposée par Piwigo. */
    public function forgetImage(int $imageId): void
    {
        $this->createQueryBuilder('c')
            ->update()
            ->set('c.coverImageId', 'NULL')
            ->set('c.focusX', PhotoAlbumCover::CENTER)
            ->set('c.focusY', PhotoAlbumCover::CENTER)
            ->where('c.coverImageId = :image')
            ->setParameter('image', $imageId)
            ->getQuery()
            ->execute();
    }

    /** Supprime la couverture d'un album (après sa suppression dans Piwigo). */
    public function deleteByAlbum(int $albumId): void
    {
        $this->createQueryBuilder('c')
            ->delete()
            ->where('c.piwigoAlbumId = :album')
            ->setParameter('album', $albumId)
            ->getQuery()
            ->execute();
    }
}
