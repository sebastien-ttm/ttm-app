<?php

namespace App\Enum;

/**
 * État d'une demande d'emprunt du minibus du club : en attente de la décision du gestionnaire du minibus, validée,
 * refusée, ou annulée (par l'emprunteur ou le gestionnaire).
 */
enum MinibusBookingStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Refused = 'refused';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'En attente de validation',
            self::Approved => 'Validée',
            self::Refused => 'Refusée',
            self::Cancelled => 'Annulée',
        };
    }

    /** Une demande « vivante » occupe (ou pourrait occuper) le calendrier ; refusée / annulée, non. */
    public function isLive(): bool
    {
        return $this === self::Pending || $this === self::Approved;
    }
}
