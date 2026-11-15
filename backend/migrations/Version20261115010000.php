<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Comité directeur : commentaires / réponses des membres sur les points des autres, et actions confiées à un ou
 * plusieurs porteurs (membres du CoDir ou autres adhérents) en séance.
 */
final class Version20261115010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crée board_item_comment, board_action et board_action_assignee (commentaires et actions du CoDir).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('
            CREATE TABLE board_item_comment (
                id INT AUTO_INCREMENT NOT NULL,
                item_id INT NOT NULL,
                author_id INT DEFAULT NULL,
                author_name VARCHAR(120) NOT NULL,
                text LONGTEXT NOT NULL,
                created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                INDEX idx_board_item_comment_item (item_id),
                INDEX idx_board_item_comment_author (author_id),
                PRIMARY KEY(id),
                CONSTRAINT fk_board_item_comment_item FOREIGN KEY (item_id) REFERENCES board_agenda_item (id) ON DELETE CASCADE,
                CONSTRAINT fk_board_item_comment_author FOREIGN KEY (author_id) REFERENCES `user` (id) ON DELETE SET NULL
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        ');

        $this->addSql('
            CREATE TABLE board_action (
                id INT AUTO_INCREMENT NOT NULL,
                item_id INT NOT NULL,
                text LONGTEXT NOT NULL,
                created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                INDEX idx_board_action_item (item_id),
                PRIMARY KEY(id),
                CONSTRAINT fk_board_action_item FOREIGN KEY (item_id) REFERENCES board_agenda_item (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        ');

        $this->addSql('
            CREATE TABLE board_action_assignee (
                action_id INT NOT NULL,
                user_id INT NOT NULL,
                INDEX idx_board_action_assignee_action (action_id),
                INDEX idx_board_action_assignee_user (user_id),
                PRIMARY KEY(action_id, user_id),
                CONSTRAINT fk_board_action_assignee_action FOREIGN KEY (action_id) REFERENCES board_action (id) ON DELETE CASCADE,
                CONSTRAINT fk_board_action_assignee_user FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        ');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE board_action_assignee');
        $this->addSql('DROP TABLE board_action');
        $this->addSql('DROP TABLE board_item_comment');
    }
}
