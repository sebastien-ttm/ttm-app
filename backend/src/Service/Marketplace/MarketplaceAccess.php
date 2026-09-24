<?php

namespace App\Service\Marketplace;

use App\Entity\MarketplaceSettings;
use App\Entity\User;
use App\Repository\MarketplaceSettingsRepository;

/**
 * Qui a accès à la bourse aux équipements.
 *
 * Source de vérité : la page « Réglages de la bourse » du backend
 * (MarketplaceSettings) — fermée / testeurs uniquement / tout le club.
 * Tant que cette page n'a jamais été enregistrée, on retombe sur la
 * variable d'environnement MARKETPLACE_TESTER_EMAILS (liste séparée par
 * des virgules ; vide = personne ; « * » = tout le monde).
 *
 * E-mails comparés en minuscules. Un profil dépendant qui partage
 * l'adresse d'un testeur y a donc accès aussi (même boîte mail).
 */
class MarketplaceAccess
{
    private ?MarketplaceSettings $settings = null;
    private bool $settingsLoaded = false;

    public function __construct(
        private readonly MarketplaceSettingsRepository $settingsRepo,
        private readonly string $envTesterEmails,
    ) {
    }

    public function isEnabledFor(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        $settings = $this->settings();
        if ($settings !== null) {
            return match ($settings->getMode()) {
                MarketplaceSettings::MODE_EVERYONE => true,
                MarketplaceSettings::MODE_TESTERS => $this->isListed($user, $settings->getTesterEmailList()),
                default => false,
            };
        }

        $envList = MarketplaceSettings::parseEmailList($this->envTesterEmails);
        return in_array('*', $envList, true) || $this->isListed($user, $envList);
    }

    /** @param list<string> $emails */
    private function isListed(User $user, array $emails): bool
    {
        return in_array(mb_strtolower($user->getEmail(), 'UTF-8'), $emails, true);
    }

    /** Lu une seule fois par requête (le voter et l'endpoint d'accès l'appellent tous les deux). */
    private function settings(): ?MarketplaceSettings
    {
        if (!$this->settingsLoaded) {
            $this->settings = $this->settingsRepo->findCurrent();
            $this->settingsLoaded = true;
        }
        return $this->settings;
    }
}
