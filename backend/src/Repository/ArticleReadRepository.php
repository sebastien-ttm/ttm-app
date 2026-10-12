<?php

namespace App\Repository;

use App\Entity\Article;
use App\Entity\ArticleRead;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ArticleRead>
 */
class ArticleReadRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ArticleRead::class);
    }

    /**
     * Parmi ces articles, ceux que l'adhérent a déjà lus.
     *
     * @param list<int> $articleIds
     *
     * @return array<int, true> identifiants des articles lus (en clé)
     */
    public function readIdsForUser(User $user, array $articleIds): array
    {
        if ($articleIds === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('r')
            ->select('IDENTITY(r.article) AS articleId')
            ->where('r.user = :user')
            ->andWhere('r.article IN (:ids)')
            ->setParameter('user', $user)
            ->setParameter('ids', $articleIds)
            ->getQuery()
            ->getArrayResult();

        $read = [];
        foreach ($rows as $row) {
            $read[(int) $row['articleId']] = true;
        }

        return $read;
    }

    /**
     * Marque l'article comme lu (sans effet s'il l'est déjà). Passe par le SQL direct (INSERT IGNORE)
     * plutôt que par l'ORM : deux requêtes concurrentes ne peuvent pas planter sur l'index unique, et un
     * échec n'invalide pas l'EntityManager. Best-effort : ne doit jamais empêcher d'afficher l'article.
     */
    public function markRead(User $user, Article $article): void
    {
        try {
            $this->getEntityManager()->getConnection()->executeStatement(
                'INSERT IGNORE INTO article_read (user_id, article_id, read_at) VALUES (?, ?, ?)',
                [$user->getId(), $article->getId(), (new \DateTimeImmutable())->format('Y-m-d H:i:s')],
            );
        } catch (DbalException) {
            // Suivi de lecture non essentiel : on ignore.
        }
    }
}
