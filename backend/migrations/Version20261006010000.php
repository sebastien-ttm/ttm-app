<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Émargement de la remise des bonnets de bain individuels du club
 * (une ligne par remise, remplacements compris).
 */
final class Version20261006010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crée cap_distribution (remises de bonnets du club).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE cap_distribution (
            id INT AUTO_INCREMENT NOT NULL,
            user_id INT NOT NULL,
            distributed_by_id INT DEFAULT NULL,
            distributed_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            INDEX idx_cap_distribution_user (user_id, distributed_at),
            INDEX idx_cap_distribution_by (distributed_by_id),
            PRIMARY KEY(id),
            CONSTRAINT FK_CAP_DISTRIBUTION_USER FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE,
            CONSTRAINT FK_CAP_DISTRIBUTION_BY FOREIGN KEY (distributed_by_id) REFERENCES `user` (id) ON DELETE SET NULL
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE cap_distribution');
    }
}
