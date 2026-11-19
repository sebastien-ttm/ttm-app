<?php

namespace App\Enum;

/**
 * Cycle de vie d'une inscription payante à un événement (EventOrder) :
 *
 *   Pending ──paiement confirmé par HelloAsso──▶ Paid
 *      ├──l'adhérent annule──▶ Canceled
 *      └──aucun paiement après le délai──▶ Abandoned
 *
 * Une commande en attente réserve ses places (voir EventOrder::$expiresAt) ; annulée ou abandonnée, elle les libère.
 */
enum EventOrderStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Abandoned = 'abandoned';
    case Canceled = 'canceled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'En attente de paiement',
            self::Paid => 'Payée',
            self::Abandoned => 'Abandonnée',
            self::Canceled => 'Annulée',
        };
    }

    /** @return array<string, string> libellé => valeur (filtres du back-office) */
    public static function choices(): array
    {
        $choices = [];
        foreach (self::cases() as $case) {
            $choices[$case->label()] = $case->value;
        }

        return $choices;
    }
}
