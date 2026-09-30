<?php

namespace App\Enum;

/**
 * Motif d'une déclaration d'absence du staff (encadrant / entraîneur),
 * sur une journée (StaffDayUnavailability) ou une semaine entière
 * (StaffWeekUnavailability).
 */
enum StaffAbsenceReason: string
{
    case Maladie = 'maladie';
    case Vacances = 'vacances';
    case Deplacement = 'deplacement';

    public function label(): string
    {
        return match ($this) {
            self::Maladie => 'Maladie',
            self::Vacances => 'Vacances',
            self::Deplacement => 'Déplacement',
        };
    }

    /** Emoji utilisé côté mobile/admin pour identifier rapidement le motif. */
    public function icon(): string
    {
        return match ($this) {
            self::Maladie => '🤒',
            self::Vacances => '🏖️',
            self::Deplacement => '🚗',
        };
    }
}
