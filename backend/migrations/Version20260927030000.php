<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Bourse aux dossards : offres de dossards (don / revente) + ouverture
 * des discussions de la bourse à ces offres (marketplace_conversation
 * porte soit listing_id, soit bib_offer_id).
 */
final class Version20260927030000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crée bib_offer et rattache marketplace_conversation aux offres de dossards.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE bib_offer (
            id INT AUTO_INCREMENT NOT NULL,
            author_id INT NOT NULL,
            race_name VARCHAR(150) NOT NULL,
            race_date DATE NOT NULL COMMENT \'(DC2Type:date_immutable)\',
            quantity INT NOT NULL,
            exchange_type VARCHAR(10) NOT NULL,
            unit_price_cents INT DEFAULT NULL,
            negotiable TINYINT(1) NOT NULL,
            description LONGTEXT DEFAULT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            paused_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            INDEX idx_bib_offer_author (author_id),
            INDEX idx_bib_offer_race_date (race_date),
            PRIMARY KEY(id),
            CONSTRAINT FK_BIB_OFFER_AUTHOR FOREIGN KEY (author_id) REFERENCES `user` (id) ON DELETE CASCADE
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('ALTER TABLE marketplace_conversation MODIFY listing_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE marketplace_conversation ADD bib_offer_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE marketplace_conversation ADD CONSTRAINT FK_MPC_BIB_OFFER FOREIGN KEY (bib_offer_id) REFERENCES bib_offer (id) ON DELETE CASCADE');
        $this->addSql('CREATE UNIQUE INDEX uniq_mp_conversation_bib_buyer ON marketplace_conversation (bib_offer_id, buyer_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DELETE FROM marketplace_conversation WHERE bib_offer_id IS NOT NULL');
        $this->addSql('ALTER TABLE marketplace_conversation DROP FOREIGN KEY FK_MPC_BIB_OFFER');
        $this->addSql('DROP INDEX uniq_mp_conversation_bib_buyer ON marketplace_conversation');
        $this->addSql('ALTER TABLE marketplace_conversation DROP bib_offer_id');
        $this->addSql('ALTER TABLE marketplace_conversation MODIFY listing_id INT NOT NULL');
        $this->addSql('DROP TABLE bib_offer');
    }
}
