<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Notifications e-mail : seuls les plans d'entraînement sont activés par défaut sur un nouveau compte
 * (nouveaux articles et mailings du club : à cocher dans le profil).
 *
 * Ne change que la valeur par défaut de la colonne (les lignes existantes ne sont pas modifiées) ; la
 * désinscription d'office des mailings pour un nouveau compte est faite par le constructeur de User.
 */
final class Version20261115050000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'user.notify_training_plan_email : valeur par défaut à 1 (nouveaux comptes).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` CHANGE notify_training_plan_email notify_training_plan_email TINYINT(1) DEFAULT 1 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` CHANGE notify_training_plan_email notify_training_plan_email TINYINT(1) DEFAULT 0 NOT NULL');
    }
}
