<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Bourse aux équipements : prix facultatif et expiration des annonces au bout de 10 jours.
 *  - price : prix en euros, facultatif ;
 *  - published_at : mise en ligne / dernière réactivation, point de départ des 10 jours. Les annonces existantes
 *    repartent d'aujourd'hui (sinon toutes celles de plus de 10 jours disparaîtraient d'un coup à la mise en
 *    production) : elles expirent donc 10 jours après le déploiement si leur auteur ne les réactive pas.
 */
final class Version20261016010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'marketplace_listing : price (facultatif) et published_at (expiration à 10 jours).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE marketplace_listing ADD price NUMERIC(8, 2) DEFAULT NULL, ADD published_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)'");
        $this->addSql('UPDATE marketplace_listing SET published_at = NOW()');
        $this->addSql("ALTER TABLE marketplace_listing MODIFY published_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE marketplace_listing DROP price, DROP published_at');
    }
}
