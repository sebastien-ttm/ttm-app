<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Le compte principal d'un e-mail partagé (celui auquel d'autres profils sont « rattachés », colonne linked_to_user_id)
 * reçoit le profil Parent, comme ceux qui ont des enfants déclarés (Version20261020020000). Désormais automatique à chaque
 * rattachement (User::setLinkedToUser, import CSV, admin) ; cette migration rattrape les comptes existants.
 */
final class Version20261020030000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute le profil Parent aux comptes principaux d\'un e-mail partagé.';
    }

    public function up(Schema $schema): void
    {
        // Table dérivée : MySQL interdit de lire la table mise à jour dans une sous-requête directe (erreur 1093).
        $this->addSql(<<<'SQL'
            UPDATE `user`
            SET profiles = JSON_ARRAY_APPEND(profiles, '$', 'parent')
            WHERE id IN (
                SELECT primaries.linked_to_user_id FROM (
                    SELECT DISTINCT linked_to_user_id FROM `user` WHERE linked_to_user_id IS NOT NULL
                ) AS primaries
            )
              AND JSON_CONTAINS(profiles, '"parent"') = 0
            SQL);
    }

    public function down(Schema $schema): void
    {
        // Les profils d'origine ne sont pas reconstituables : sans effet.
    }
}
