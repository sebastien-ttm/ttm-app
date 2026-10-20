<?php

namespace App\Repository;

use App\Entity\User;
use App\Entity\YouthDocument;
use App\Enum\YouthDocumentType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<YouthDocument>
 */
class YouthDocumentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, YouthDocument::class);
    }

    public function findOneByOwnerAndType(User $owner, YouthDocumentType $type): ?YouthDocument
    {
        return $this->findOneBy(['owner' => $owner, 'type' => $type]);
    }

    /**
     * Documents de plusieurs jeunes en une requête (liste du staff), groupés par jeune puis par type.
     *
     * @param list<User> $owners
     *
     * @return array<int, array<string, YouthDocument>> identifiant du jeune → [valeur du type → document]
     */
    public function findGroupedByOwner(array $owners): array
    {
        if ($owners === []) {
            return [];
        }
        $rows = $this->createQueryBuilder('d')
            ->addSelect('ub')
            ->leftJoin('d.uploadedBy', 'ub')
            ->where('d.owner IN (:owners)')
            ->setParameter('owners', $owners)
            ->getQuery()
            ->getResult();

        $out = [];
        foreach ($rows as $doc) {
            $out[(int) $doc->getOwner()->getId()][$doc->getType()->value] = $doc;
        }

        return $out;
    }
}
