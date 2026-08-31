<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Renomme le profil U25 en Performance :
 *  - user.profiles est un JSON array → remplace la valeur "u25" par "performance"
 *    partout où elle apparait (REPLACE sur le CAST texte de la colonne).
 *  - membership_fee.profile est un simple VARCHAR → UPDATE direct.
 */
final class Version20260830220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rename profile U25 into Performance in user.profiles and membership_fee.profile.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE user SET profiles = REPLACE(profiles, '\"u25\"', '\"performance\"') WHERE profiles LIKE '%\"u25\"%'");
        $this->addSql("UPDATE membership_fee SET profile = 'performance' WHERE profile = 'u25'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE user SET profiles = REPLACE(profiles, '\"performance\"', '\"u25\"') WHERE profiles LIKE '%\"performance\"%'");
        $this->addSql("UPDATE membership_fee SET profile = 'u25' WHERE profile = 'performance'");
    }
}
