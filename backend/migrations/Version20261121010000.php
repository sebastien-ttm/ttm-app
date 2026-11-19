<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Inscription payante aux événements (paiement HelloAsso Checkout) : tarifs d'un événement (`event_tariff`),
 * inscriptions payées ou en attente (`event_order`) et leurs lignes (`event_order_line`).
 *
 * Additive (trois nouvelles tables). À exécuter AVANT de déployer le nouveau code (qui lit ces tables).
 * Les commandes gardent le titre de l'événement et le nom de l'adhérent : supprimer l'un ou l'autre ne les supprime pas.
 */
final class Version20261121010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crée event_tariff, event_order et event_order_line (inscription payante HelloAsso Checkout).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('
            CREATE TABLE event_tariff (
                id INT AUTO_INCREMENT NOT NULL,
                event_id INT NOT NULL,
                label VARCHAR(120) NOT NULL,
                description VARCHAR(255) DEFAULT NULL,
                amount_cents INT NOT NULL,
                max_places INT DEFAULT NULL,
                audience JSON NOT NULL DEFAULT \'[]\',
                created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                INDEX idx_event_tariff_event (event_id),
                PRIMARY KEY(id),
                CONSTRAINT fk_event_tariff_event FOREIGN KEY (event_id) REFERENCES event (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        ');

        $this->addSql('
            CREATE TABLE event_order (
                id INT AUTO_INCREMENT NOT NULL,
                event_id INT DEFAULT NULL,
                event_title VARCHAR(200) NOT NULL,
                user_id INT DEFAULT NULL,
                payer_name VARCHAR(200) NOT NULL,
                status VARCHAR(16) NOT NULL,
                total_cents INT NOT NULL,
                checkout_intent_id INT DEFAULT NULL,
                checkout_url VARCHAR(1000) DEFAULT NULL,
                checkout_created_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                helloasso_order_id INT DEFAULT NULL,
                created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                expires_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                paid_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                last_checked_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                INDEX idx_event_order_event (event_id),
                INDEX idx_event_order_user (user_id),
                INDEX idx_event_order_status (status),
                INDEX idx_event_order_checkout (checkout_intent_id),
                PRIMARY KEY(id),
                CONSTRAINT fk_event_order_event FOREIGN KEY (event_id) REFERENCES event (id) ON DELETE SET NULL,
                CONSTRAINT fk_event_order_user FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE SET NULL
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        ');

        $this->addSql('
            CREATE TABLE event_order_line (
                id INT AUTO_INCREMENT NOT NULL,
                order_id INT NOT NULL,
                tariff_id INT DEFAULT NULL,
                label VARCHAR(120) NOT NULL,
                unit_amount_cents INT NOT NULL,
                quantity INT NOT NULL,
                INDEX idx_event_order_line_order (order_id),
                INDEX idx_event_order_line_tariff (tariff_id),
                PRIMARY KEY(id),
                CONSTRAINT fk_event_order_line_order FOREIGN KEY (order_id) REFERENCES event_order (id) ON DELETE CASCADE,
                CONSTRAINT fk_event_order_line_tariff FOREIGN KEY (tariff_id) REFERENCES event_tariff (id) ON DELETE SET NULL
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        ');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE event_order_line');
        $this->addSql('DROP TABLE event_order');
        $this->addSql('DROP TABLE event_tariff');
    }
}
