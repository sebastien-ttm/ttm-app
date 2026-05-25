<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Inscription parent via mobile :
 *  - num_licence devient nullable (les comptes parents non adhérents n'en ont pas)
 *  - nouvelle table user_parent_child : relation many-to-many self-ref
 *    entre comptes parents et comptes enfants (indépendamment du
 *    mécanisme linkedToUser de l'e-mail partagé).
 */
final class Version20260525145607 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'User : num_licence nullable + relation many-to-many parent/enfant';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` CHANGE num_licence num_licence VARCHAR(32) DEFAULT NULL');

        $this->addSql("CREATE TABLE user_parent_child (
            parent_id INT NOT NULL,
            child_id INT NOT NULL,
            INDEX IDX_user_parent_child_parent (parent_id),
            INDEX IDX_user_parent_child_child (child_id),
            PRIMARY KEY(parent_id, child_id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");

        $this->addSql('ALTER TABLE user_parent_child ADD CONSTRAINT FK_user_parent_child_parent FOREIGN KEY (parent_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE user_parent_child ADD CONSTRAINT FK_user_parent_child_child FOREIGN KEY (child_id) REFERENCES `user` (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_parent_child DROP FOREIGN KEY FK_user_parent_child_parent');
        $this->addSql('ALTER TABLE user_parent_child DROP FOREIGN KEY FK_user_parent_child_child');
        $this->addSql('DROP TABLE user_parent_child');

        $this->addSql('UPDATE `user` SET num_licence = CONCAT(\'TEMP-\', id) WHERE num_licence IS NULL');
        $this->addSql('ALTER TABLE `user` CHANGE num_licence num_licence VARCHAR(32) NOT NULL');
    }
}
