<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adhésions : ventilation du paiement lue à l'import CSV FFTri (Part FFTri, Assurance, Part ligue, Part club, Total),
 * utilisée par la facture, et montant facturé surchargeable par l'admin. Montants en centimes.
 */
final class Version20261019010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'user_season_membership : part_fftri_cents, assurance_cents, part_ligue_cents, part_club_cents, total_cents, invoice_amount_cents.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_season_membership ADD part_fftri_cents INT DEFAULT NULL, ADD assurance_cents INT DEFAULT NULL, ADD part_ligue_cents INT DEFAULT NULL, ADD part_club_cents INT DEFAULT NULL, ADD total_cents INT DEFAULT NULL, ADD invoice_amount_cents INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_season_membership DROP part_fftri_cents, DROP assurance_cents, DROP part_ligue_cents, DROP part_club_cents, DROP total_cents, DROP invoice_amount_cents');
    }
}
