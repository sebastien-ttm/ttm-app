<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Bourse aux équipements : « J'aime » sur une annonce. L'annonce aimée reste dans la liste de l'adhérent tant que son
 * auteur ne l'a pas supprimée, même expirée ou en pause.
 */
final class Version20261115040000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crée marketplace_listing_like (« J\'aime » sur les annonces de la bourse aux équipements).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE marketplace_listing_like (
            id INT AUTO_INCREMENT NOT NULL,
            listing_id INT NOT NULL,
            user_id INT NOT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            UNIQUE INDEX uniq_marketplace_listing_like_user (listing_id, user_id),
            INDEX IDX_MARKETPLACE_LISTING_LIKE_USER (user_id),
            PRIMARY KEY(id),
            CONSTRAINT FK_MARKETPLACE_LISTING_LIKE_LISTING FOREIGN KEY (listing_id) REFERENCES marketplace_listing (id) ON DELETE CASCADE,
            CONSTRAINT FK_MARKETPLACE_LISTING_LIKE_USER FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE marketplace_listing_like');
    }
}
