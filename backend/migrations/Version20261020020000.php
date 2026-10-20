<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Répare les comptes qui ont des enfants déclarés (table user_parent_child) mais pas le profil Parent : le lien avait été
 * créé depuis l'admin (le formulaire ne coche pas le profil) ou avant que l'appli l'ajoute d'office. Sans ce profil, le
 * parent n'a pas la gestion des enfants dans l'appli. Désormais le profil est ajouté à chaque rattachement (User::addChild,
 * admin).
 */
final class Version20261020020000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute le profil Parent aux comptes ayant des enfants rattachés.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE `user`
            SET profiles = JSON_ARRAY_APPEND(profiles, '$', 'parent')
            WHERE id IN (SELECT parent_id FROM user_parent_child)
              AND JSON_CONTAINS(profiles, '"parent"') = 0
            SQL);
    }

    public function down(Schema $schema): void
    {
        // Les profils d'origine ne sont pas reconstituables : sans effet.
    }
}
