<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Comité directeur : échéance facultative d'une action et date de clôture (une action reste « en cours » tant
 * qu'elle n'est pas clôturée).
 */
final class Version20261115020000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute due_date et closed_at à board_action (échéance et clôture des actions du CoDir).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('
            ALTER TABLE board_action
                ADD due_date DATE DEFAULT NULL COMMENT \'(DC2Type:date_immutable)\',
                ADD closed_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                ADD INDEX idx_board_action_closed_at (closed_at)
        ');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE board_action DROP INDEX idx_board_action_closed_at, DROP due_date, DROP closed_at');
    }
}
