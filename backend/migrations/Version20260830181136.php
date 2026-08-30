<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Facturation d'adhésion :
 *  - Table membership_fee (grille tarifaire saison × profil × type)
 *  - Table invoice_settings (singleton : adresse, président, signature)
 *  - Colonne user_season_membership.payment_type + invoiced_at
 */
final class Version20260830181136 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Facturation adhésion : membership_fee, invoice_settings, payment_type sur membership';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('
            CREATE TABLE membership_fee (
                id INT AUTO_INCREMENT NOT NULL,
                season_id INT NOT NULL,
                profile VARCHAR(24) NOT NULL,
                type_licence VARCHAR(24) NOT NULL,
                amount_cents INT NOT NULL,
                updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                UNIQUE INDEX uniq_fee_season_profile_type (season_id, profile, type_licence),
                INDEX IDX_fee_season (season_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        ');
        $this->addSql('ALTER TABLE membership_fee ADD CONSTRAINT fk_fee_season FOREIGN KEY (season_id) REFERENCES training_season (id) ON DELETE CASCADE');

        $this->addSql('
            CREATE TABLE invoice_settings (
                id INT AUTO_INCREMENT NOT NULL,
                club_name VARCHAR(200) NOT NULL,
                club_address LONGTEXT NOT NULL,
                president_name VARCHAR(200) NOT NULL,
                signature_filename VARCHAR(255) DEFAULT NULL,
                legal_footer LONGTEXT DEFAULT NULL,
                updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        ');
        $this->addSql('INSERT INTO invoice_settings (club_name, club_address, president_name, updated_at) VALUES (
            \'Triathlon Toulouse Métropole\',
            \'Adresse du club (à compléter dans le back-office)\',
            \'Nom du président (à compléter)\',
            NOW()
        )');

        $this->addSql('ALTER TABLE user_season_membership
            ADD payment_type VARCHAR(24) NOT NULL DEFAULT \'cb\',
            ADD invoiced_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'
        ');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_season_membership DROP payment_type, DROP invoiced_at');
        $this->addSql('DROP TABLE invoice_settings');
        $this->addSql('DROP TABLE membership_fee');
    }
}
