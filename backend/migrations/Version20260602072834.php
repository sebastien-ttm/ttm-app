<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Avatar utilisateur : nom du fichier stocké dans
 * public/uploads/avatars/{hash}.{ext}. Affiché en rond côté mobile,
 * cropé carré côté serveur (cf. ImageResizer::cropAndResizeToSquare).
 */
final class Version20260602072834 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'User.avatar_filename';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` ADD avatar_filename VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` DROP avatar_filename');
    }
}
