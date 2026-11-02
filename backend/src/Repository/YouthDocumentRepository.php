<?php

namespace App\Repository;

use App\Entity\Stage;
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

    /** Le document d'un type : permanent (`$stage` null) ou rattaché à ce stage. */
    public function findOneByOwnerAndType(User $owner, YouthDocumentType $type, ?Stage $stage = null): ?YouthDocument
    {
        return $this->findOneBy(['owner' => $owner, 'type' => $type, 'stage' => $stage]);
    }

    /**
     * Documents d'un type rattachés à un stage, pour plusieurs jeunes (participants du stage).
     *
     * @param list<User> $owners
     *
     * @return array<int, YouthDocument> identifiant du jeune → document
     */
    public function findForStage(Stage $stage, YouthDocumentType $type, array $owners): array
    {
        if ($owners === []) {
            return [];
        }
        $out = [];
        foreach ($this->findBy(['stage' => $stage, 'type' => $type, 'owner' => $owners]) as $doc) {
            $out[(int) $doc->getOwner()->getId()] = $doc;
        }

        return $out;
    }

    /**
     * Documents d'un type d'un jeune pour plusieurs stages.
     *
     * @param list<Stage> $stages
     *
     * @return array<int, YouthDocument> identifiant du stage → document
     */
    public function findForOwnerByStage(User $owner, YouthDocumentType $type, array $stages): array
    {
        if ($stages === []) {
            return [];
        }
        $out = [];
        foreach ($this->findBy(['owner' => $owner, 'type' => $type, 'stage' => $stages]) as $doc) {
            $out[(int) $doc->getStage()?->getId()] = $doc;
        }

        return $out;
    }

    /**
     * Documents permanents (sans stage) de plusieurs jeunes en une requête (liste du staff), groupés par jeune puis par
     * type.
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
            ->andWhere('d.stage IS NULL')
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
