<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Notifications push par sujet : liste des sujets que l'adhérent a désactivés (null = tout activé).
 */
final class Version20261115060000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute user.push_disabled_topics (sujets de notification push désactivés par l\'adhérent).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` ADD push_disabled_topics JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` DROP push_disabled_topics');
    }
}
