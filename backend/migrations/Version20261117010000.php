<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Comité directeur : réponse à un commentaire. Ajoute `parent_id` (auto-référence, nullable) à board_item_comment.
 * Additive : les commentaires existants restent des commentaires de premier niveau (parent_id NULL).
 *
 * À exécuter AVANT de déployer le nouveau code (qui lit cette colonne).
 */
final class Version20261117010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'board_item_comment : parent_id (réponse à un commentaire) ; les commentaires existants restent de premier niveau.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE board_item_comment ADD parent_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE board_item_comment ADD CONSTRAINT fk_board_item_comment_parent FOREIGN KEY (parent_id) REFERENCES board_item_comment (id) ON DELETE CASCADE');
        $this->addSql('CREATE INDEX idx_board_item_comment_parent ON board_item_comment (parent_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE board_item_comment DROP FOREIGN KEY fk_board_item_comment_parent');
        $this->addSql('DROP INDEX idx_board_item_comment_parent ON board_item_comment');
        $this->addSql('ALTER TABLE board_item_comment DROP parent_id');
    }
}
