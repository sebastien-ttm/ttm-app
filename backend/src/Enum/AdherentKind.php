<?php

namespace App\Enum;

/**
 * Distingue les cas destinataires d'un contenu :
 *  - `All`     : s'applique aux deux (défaut historique / rétrocompat)
 *  - `New`     : réservé au premier import d'un adhérent
 *  - `Renewal` : réservé au renouvellement d'un adhérent connu
 *
 * Utilisé par WelcomeEmailTemplate et ClubCharter — le résolveur
 * cherche d'abord une entrée pour le kind exact, puis retombe sur
 * `All` si non trouvée.
 */
enum AdherentKind: string
{
    case All = 'all';
    case New = 'new';
    case Renewal = 'renewal';

    public function label(): string
    {
        return match ($this) {
            self::All => 'Nouveaux + renouvellements',
            self::New => 'Nouveaux adhérents uniquement',
            self::Renewal => 'Renouvellements uniquement',
        };
    }

    /** @return array<string, self> */
    public static function choices(): array
    {
        $out = [];
        foreach (self::cases() as $c) { $out[$c->label()] = $c; }
        return $out;
    }
}
