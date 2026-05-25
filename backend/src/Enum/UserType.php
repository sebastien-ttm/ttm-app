<?php

namespace App\Enum;

/**
 * Provenance / nature administrative d'un compte utilisateur.
 *
 *  - Adherent : licencié FFTri, créé via import CSV (ou manuellement
 *               par l'admin). A typiquement un numLicence.
 *  - Externe  : compte créé en dehors de l'import FFTri — typiquement
 *               un parent qui s'inscrit via mobile, ou un compte
 *               manuel sans licence (intervenant ponctuel, etc.).
 */
enum UserType: string
{
    case Adherent = 'adherent';
    case Externe = 'externe';

    public function label(): string
    {
        return match ($this) {
            self::Adherent => 'Adhérent',
            self::Externe => 'Externe',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Adherent => 'success',
            self::Externe => 'warning',
        };
    }

    /** @return array<string, self> */
    public static function enumChoices(): array
    {
        $out = [];
        foreach (self::cases() as $c) {
            $out[$c->label()] = $c;
        }
        return $out;
    }
}
