<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * User.sub_type : précise le type d'utilisateur.
 *
 *  - type=adherent : sub_type='club' (défaut) ou 'autre_club' (licencié ailleurs,
 *                    créé manuellement par l'admin)
 *  - type=externe  : sub_type='parent' (défaut) ou 'ami' (ami du club, ancien adhérent)
 *
 * Valeur par défaut 'club' compatible avec les adhérents importés via CSV.
 * Les comptes externes existants seront mis à 'parent' explicitement.
 */
final class Version20260603153617 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'User : ajout sub_type (club/autre_club ; parent/ami)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE `user` ADD sub_type VARCHAR(32) NOT NULL DEFAULT 'club'");
        // Les comptes externes existants : par défaut ce sont des parents
        // (inscrits via le workflow mobile). On les bascule explicitement.
        $this->addSql("UPDATE `user` SET sub_type = 'parent' WHERE type = 'externe'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` DROP sub_type');
    }
}
