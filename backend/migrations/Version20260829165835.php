<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * WelcomeEmailTemplate : modèle d'email de bienvenue personnalisable
 * envoyé aux nouveaux adhérents importés depuis le CSV FFTri.
 *
 * Seed la ligne unique avec un contenu par défaut cliquable dès l'import
 * suivant (l'admin peut ensuite l'éditer via EasyAdmin).
 */
final class Version20260829165835 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'WelcomeEmailTemplate : modèle éditable d\'email de bienvenue';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('
            CREATE TABLE welcome_email_template (
                id INT AUTO_INCREMENT NOT NULL,
                subject VARCHAR(200) NOT NULL,
                body_html LONGTEXT NOT NULL,
                updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        ');

        // Seed du contenu par défaut. HTML compact, prêt à copier-adapter.
        $defaultBody = <<<'HTML'
<p>Bonjour {{ prenom }},</p>
<p>Bienvenue au <strong>Triathlon Toulouse Métropole</strong> ! Votre adhésion est bien enregistrée et votre compte sur l'application du club est prêt.</p>
<h3>Se connecter à l'application</h3>
<p>Cliquez sur le bouton ci-dessous pour accéder à votre espace personnel. Ce lien est unique et vous connecte automatiquement — vous pourrez ensuite définir un mot de passe depuis votre profil.</p>
<p style="text-align:center; margin:24px 0;">
    <a href="{{ magic_link }}" style="background:#D32F2F; color:#fff; padding:12px 24px; border-radius:6px; text-decoration:none; font-weight:700;">🚀 Ouvrir l'application</a>
</p>
<h3>Fonctionnement du club</h3>
<ul>
    <li><strong>Créneaux d'entraînement</strong> : consultez votre planning hebdomadaire dans l'onglet Entraînement de l'appli.</li>
    <li><strong>Plans d'entraînement</strong> : les entraîneurs publient chaque semaine des plans PDF téléchargeables.</li>
    <li><strong>Actualités &amp; événements</strong> : compétitions, sorties club, actus fédérales — tout est centralisé dans l'appli.</li>
    <li><strong>Communication</strong> : questions au bureau via la messagerie intégrée.</li>
</ul>
<p>À très vite sur les bords du canal !</p>
<p><em>L'équipe TTM</em></p>
HTML;

        $this->addSql(
            'INSERT INTO welcome_email_template (subject, body_html, updated_at) VALUES (?, ?, NOW())',
            ['Bienvenue au Triathlon Toulouse Métropole !', $defaultBody],
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE welcome_email_template');
    }
}
