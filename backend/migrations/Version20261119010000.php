<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * - `user.club_email` : adresse e-mail du club de l'adhérent, distincte de l'e-mail personnel (`email`, celui de FFTRi) ;
 *   NULL = pas d'adresse du club (on se rabat sur l'e-mail personnel).
 * - `board_meeting.google_event_id` : identifiant de l'événement Google Agenda créé pour la réunion du CoDir
 *   (NULL = aucune invitation envoyée).
 *
 * Additive. À exécuter AVANT de déployer le nouveau code (qui lit ces colonnes).
 */
final class Version20261119010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'user.club_email (e-mail du club) ; board_meeting.google_event_id (événement Google Agenda de la réunion).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` ADD club_email VARCHAR(180) DEFAULT NULL');
        $this->addSql('ALTER TABLE board_meeting ADD google_event_id VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE board_meeting DROP google_event_id');
        $this->addSql('ALTER TABLE `user` DROP club_email');
    }
}
