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
    case Fftri = 'fftri';
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
            self::Fftri => 'CB (encaissé par la FFTri)',
            self::Autre => 'Autre',
        };
    }

    /**
     * true = l'argent n'a pas transité par le club (encaissé directement
     * par la FFTri via l'Espace Tri) : le document généré doit être une
     * attestation de paiement, pas une facture (le club n'a rien encaissé
     * et ne peut pas légalement le prétendre).
     */
    public function isCollectedByFftri(): bool
    {
        return $this === self::Fftri;
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
