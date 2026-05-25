<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Refonte du modèle User : 3 axes orthogonaux clairs.
 *
 *  - type    : 'adherent' | 'externe' (provenance)
 *  - profiles[] : ce que la personne EST (jeune/senior/u25/parent/entraineur/encadrant)
 *  - role    : 'user' | 'admin' (gate d'accès)
 *
 * Supprime les colonnes redondantes :
 *  - categorie (jeune/senior est déjà dans profiles[])
 *  - roles[] (remplacé par role simple)
 *
 * Migration des données :
 *  - Les users avec ROLE_ADMIN ou ROLE_COACH dans roles[] → role='admin'
 *  - Les users avec ROLE_COACH → ajoute le profil 'entraineur'
 *  - Les users avec ROLE_ENCADRANT (cf. phase 1) → conservent profile encadrant
 *  - type='adherent' par défaut (les comptes parents externes resteront
 *    à modifier à la main si besoin via SQL — ils seront marqués externe
 *    automatiquement à la création via le nouveau registerParent).
 */
final class Version20260525175255 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'User : type (adherent/externe) + role simple (user/admin), drop categorie + roles[]';
    }

    public function up(Schema $schema): void
    {
        // 1) Nouvelles colonnes
        $this->addSql("ALTER TABLE `user`
            ADD type VARCHAR(16) NOT NULL DEFAULT 'adherent',
            ADD role VARCHAR(16) NOT NULL DEFAULT 'user'");

        // 2) Migrer role depuis roles[]
        $this->addSql("UPDATE `user` SET role = 'admin'
            WHERE JSON_CONTAINS(roles, '\"ROLE_ADMIN\"') = 1
               OR JSON_CONTAINS(roles, '\"ROLE_COACH\"') = 1");

        // 3) Migrer les ROLE_COACH vers profile=entraineur
        //    JSON_ARRAY_APPEND : ajoute à la liste sans dédupliquer ; on
        //    accepte le doublon éventuel, qui sera nettoyé au prochain
        //    save (setProfiles dédoublonne).
        $this->addSql("UPDATE `user`
            SET profiles = JSON_ARRAY_APPEND(profiles, '$', 'entraineur')
            WHERE JSON_CONTAINS(roles, '\"ROLE_COACH\"') = 1
              AND JSON_CONTAINS(profiles, '\"entraineur\"') = 0");

        // 4) Type 'externe' pour les comptes sans numLicence
        //    (typiquement les parents inscrits via mobile).
        $this->addSql("UPDATE `user` SET type = 'externe' WHERE num_licence IS NULL");

        // 5) Drop des colonnes maintenant dérivées
        $this->addSql('ALTER TABLE `user` DROP categorie, DROP roles');
    }

    public function down(Schema $schema): void
    {
        $this->addSql("ALTER TABLE `user`
            ADD categorie VARCHAR(16) NOT NULL DEFAULT 'senior',
            ADD roles JSON NOT NULL");

        // Reconstitue categorie depuis profiles[]
        $this->addSql("UPDATE `user` SET categorie = 'jeune' WHERE JSON_CONTAINS(profiles, '\"jeune\"') = 1");
        $this->addSql("UPDATE `user` SET categorie = 'senior' WHERE JSON_CONTAINS(profiles, '\"senior\"') = 1");

        // Reconstitue roles depuis role + profile
        $this->addSql("UPDATE `user` SET roles = JSON_ARRAY('ROLE_USER')");
        $this->addSql("UPDATE `user` SET roles = JSON_ARRAY('ROLE_USER', 'ROLE_ADMIN') WHERE role = 'admin'");
        $this->addSql("UPDATE `user` SET roles = JSON_ARRAY_APPEND(roles, '$', 'ROLE_COACH')
            WHERE JSON_CONTAINS(profiles, '\"entraineur\"') = 1");
        $this->addSql("UPDATE `user` SET roles = JSON_ARRAY_APPEND(roles, '$', 'ROLE_ENCADRANT')
            WHERE JSON_CONTAINS(profiles, '\"encadrant\"') = 1");

        $this->addSql('ALTER TABLE `user` DROP type, DROP role');
    }
}
