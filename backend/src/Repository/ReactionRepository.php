<?php

namespace App\Repository;

use App\Entity\Article;
use App\Entity\Reaction;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Reaction>
 */
class ReactionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Reaction::class);
    }

    public function findOne(Article $article, User $user, string $emoji): ?Reaction
    {
        return $this->findOneBy(['article' => $article, 'user' => $user, 'emoji' => $emoji]);
    }

    /**
     * Toutes les réactions d'un user sur un article — utilisé pour
     * imposer l'exclusivité (1 seule réaction par user par article).
     *
     * @return list<Reaction>
     */
    public function findAllByUserAndArticle(User $user, Article $article): array
    {
        return $this->findBy(['user' => $user, 'article' => $article]);
    }
}
