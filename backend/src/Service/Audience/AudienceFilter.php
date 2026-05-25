<?php

namespace App\Service\Audience;

use App\Entity\User;
use Doctrine\ORM\QueryBuilder;

/**
 * Construit le WHERE qui filtre par audience pour un utilisateur donné.
 *
 *   audience est NULL/vide  → visible par tous
 *   audience NON vide       → visible si user a au moins 1 profil en commun
 *
 * Pratique : JSON_LENGTH() + JSON_CONTAINS() fonctionnent dans
 * MariaDB 10.2+ et MySQL 5.7+, contrairement à JSON_OVERLAPS()
 * qui n'est dispo qu'à partir de MySQL 8.
 */
class AudienceFilter
{
    /**
     * Applique le filtre WHERE sur un QueryBuilder existant.
     * L'entité doit avoir une colonne JSON nommée `audience`.
     *
     * @param string $alias  Alias de la table dans le QueryBuilder
     */
    public function apply(QueryBuilder $qb, ?User $user, string $alias = 't'): QueryBuilder
    {
        $profiles = $user?->getProfiles() ?? [];

        // Cas 1 : aucun profil utilisateur → ne voit que les contenus publics
        if ($profiles === []) {
            $qb->andWhere("JSON_LENGTH({$alias}.audience) = 0");
            return $qb;
        }

        // Cas 2 : profils présents → audience vide OU intersection non vide
        $orParts = ["JSON_LENGTH({$alias}.audience) = 0"];
        $params = [];
        foreach ($profiles as $i => $p) {
            $key = "audience_{$i}";
            $orParts[] = "JSON_CONTAINS({$alias}.audience, :{$key}) = 1";
            // JSON_CONTAINS attend une valeur JSON (string entre guillemets)
            $params[$key] = json_encode($p);
        }

        $qb->andWhere('('.implode(' OR ', $orParts).')');
        foreach ($params as $k => $v) {
            $qb->setParameter($k, $v);
        }
        return $qb;
    }

    /**
     * Test mémoire : un user voit-il ce contenu, vu son audience ?
     *
     * @param list<string> $contentAudience
     */
    public function isVisible(array $contentAudience, ?User $user): bool
    {
        if ($contentAudience === []) {
            return true;
        }
        $userProfiles = $user?->getProfiles() ?? [];
        return array_intersect($contentAudience, $userProfiles) !== [];
    }
}
