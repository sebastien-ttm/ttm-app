<?php

namespace App\Enum;

/**
 * Cycle de vie d'une compétition proposée (modération par un administrateur) :
 *
 *   Pending ──valider──▶ Validated
 *      └──refuser──▶ Refused ──(l'auteur modifie sa proposition)──▶ Pending
 *
 *  - Pending   : proposée, pas encore publiée — seul son auteur la voit (et les administrateurs, dans le back-office).
 *  - Validated : publiée, visible de tous, ouverte aux réponses.
 *  - Refused   : non retenue (ex. événement de triathlon non affilié à la FFTri) — seul son auteur la voit.
 */
enum RaceProposalStatus: string
{
    case Pending = 'pending';
    case Validated = 'validated';
    case Refused = 'refused';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'En attente de validation',
            self::Validated => 'Validée',
            self::Refused => 'Refusée',
        };
    }

    /** Publiée : visible de tous les adhérents. */
    public function isPublic(): bool
    {
        return $this === self::Validated;
    }

    /** @return list<string> valeurs des statuts visibles de tous */
    public static function publicValues(): array
    {
        return array_map(
            static fn (self $s) => $s->value,
            array_values(array_filter(self::cases(), static fn (self $s) => $s->isPublic())),
        );
    }

    /** @return array<string, string> libellé => valeur, pour les ChoiceField / ChoiceFilter du back-office */
    public static function choices(): array
    {
        $choices = [];
        foreach (self::cases() as $case) {
            $choices[$case->label()] = $case->value;
        }

        return $choices;
    }
}
