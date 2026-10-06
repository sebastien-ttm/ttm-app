<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Émargement des présences réelles aux événements soumis au vote
 * (une ligne par adhérent présent).
 */
final class Version20261006020000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crée event_check_in (émargement des présences aux événements).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE event_check_in (
            id INT AUTO_INCREMENT NOT NULL,
            event_id INT NOT NULL,
            user_id INT NOT NULL,
            checked_by_id INT DEFAULT NULL,
            checked_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            UNIQUE INDEX uniq_check_in_event_user (event_id, user_id),
            INDEX idx_check_in_user (user_id),
            INDEX idx_check_in_checked_by (checked_by_id),
            PRIMARY KEY(id),
            CONSTRAINT FK_CHECK_IN_EVENT FOREIGN KEY (event_id) REFERENCES event (id) ON DELETE CASCADE,
            CONSTRAINT FK_CHECK_IN_USER FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE,
            CONSTRAINT FK_CHECK_IN_CHECKED_BY FOREIGN KEY (checked_by_id) REFERENCES `user` (id) ON DELETE SET NULL
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE event_check_in');
    }
}
