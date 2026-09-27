<?php

namespace App\Repository;

use App\Entity\BibOffer;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BibOffer>
 */
class BibOfferRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BibOffer::class);
    }

    /**
     * Offres publiées pour des courses à venir, la course la plus proche
     * d'abord — liste « Dossards » visible par tout le club.
     *
     * @return list<BibOffer>
     */
    public function findPublishedUpcoming(): array
    {
        return $this->createQueryBuilder('b')
            ->leftJoin('b.author', 'a')->addSelect('a')
            ->where('b.pausedAt IS NULL')
            ->andWhere('b.raceDate >= :today')
            ->setParameter('today', new \DateTimeImmutable('today'), 'date_immutable')
            ->orderBy('b.raceDate', 'ASC')
            ->addOrderBy('b.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Toutes les offres d'un auteur (publiées, en pause, passées), plus
     * récentes d'abord — liste « Mes dossards ».
     *
     * @return list<BibOffer>
     */
    public function findAllByAuthor(User $author): array
    {
        return $this->createQueryBuilder('b')
            ->where('b.author = :author')
            ->setParameter('author', $author)
            ->orderBy('b.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
