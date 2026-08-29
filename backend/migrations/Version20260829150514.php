<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * GouterSignup : positionnements des parents/jeunes pour amener le goûter
 * un mercredi donné. Capacité 2 personnes par date (enforcée côté API).
 */
final class Version20260829150514 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'GouterSignup : positionnements goûter du mercredi';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('
            CREATE TABLE gouter_signup (
                id INT AUTO_INCREMENT NOT NULL,
                user_id INT NOT NULL,
                created_by_id INT DEFAULT NULL,
                gouter_date DATE NOT NULL COMMENT \'(DC2Type:date_immutable)\',
                created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                notes LONGTEXT DEFAULT NULL,
                UNIQUE INDEX uniq_gouter_date_user (gouter_date, user_id),
                INDEX idx_gouter_date (gouter_date),
                INDEX IDX_gouter_user (user_id),
                INDEX IDX_gouter_created_by (created_by_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        ');
        $this->addSql('ALTER TABLE gouter_signup ADD CONSTRAINT fk_gouter_user FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE gouter_signup ADD CONSTRAINT fk_gouter_created_by FOREIGN KEY (created_by_id) REFERENCES user (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE gouter_signup');
    }
}
