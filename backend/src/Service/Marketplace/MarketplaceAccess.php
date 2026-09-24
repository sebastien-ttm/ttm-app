<?php

namespace App\Service\Marketplace;

use App\Entity\User;

/**
 * Accès à la bourse aux équipements pendant la phase de test : réservé
 * à une liste d'adresses e-mail (variable d'environnement
 * MARKETPLACE_TESTER_EMAILS, séparées par des virgules).
 *
 *  - liste vide   → personne (fonctionnalité totalement fermée)
 *  - « * »        → tout le monde (mise en service générale)
 *  - sinon        → uniquement les comptes dont l'e-mail figure dans la liste
 *
 * Comparé en minuscules. Un profil dépendant qui partage l'adresse d'un
 * testeur y a donc accès aussi (même boîte mail).
 */
class MarketplaceAccess
{
    /** @var list<string> */
    private readonly array $testerEmails;
    private readonly bool $everyone;

    public function __construct(string $testerEmails)
    {
        $emails = array_values(array_filter(
            array_map(static fn (string $e) => mb_strtolower(trim($e), 'UTF-8'), explode(',', $testerEmails)),
            static fn (string $e) => $e !== '',
        ));
        $this->everyone = in_array('*', $emails, true);
        $this->testerEmails = $emails;
    }

    public function isEnabledFor(?User $user): bool
    {
        if ($user === null) {
            return false;
        }
        if ($this->everyone) {
            return true;
        }
        return in_array(mb_strtolower($user->getEmail(), 'UTF-8'), $this->testerEmails, true);
    }
}
