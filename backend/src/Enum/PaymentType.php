<?php

namespace App\Enum;

/**
 * Mode de paiement d'une adhésion. CB par défaut ; modifiable par
 * l'admin sur chaque UserSeasonMembership.
 */
enum PaymentType: string
{
    case CB = 'cb';
    case Especes = 'especes';
    case Cheque = 'cheque';
    case Virement = 'virement';
    case PassSport = 'pass_sport';
    case ANCV = 'ancv';
    case Autre = 'autre';

    public function label(): string
    {
        return match ($this) {
            self::CB => 'Carte bancaire',
            self::Especes => 'Espèces',
            self::Cheque => 'Chèque',
            self::Virement => 'Virement',
            self::PassSport => 'Pass\'Sport',
            self::ANCV => 'Chèques vacances (ANCV)',
            self::Autre => 'Autre',
        };
    }

    /** @return array<string, self> */
    public static function choices(): array
    {
        $out = [];
        foreach (self::cases() as $c) {
            $out[$c->label()] = $c;
        }
        return $out;
    }
}
