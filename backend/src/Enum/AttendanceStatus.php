<?php

namespace App\Enum;

/**
 * Réponse d'un adhérent au vote de présence à un événement.
 */
enum AttendanceStatus: string
{
    case Yes = 'yes';
    case No = 'no';
    case Maybe = 'maybe';

    public function label(): string
    {
        return match ($this) {
            self::Yes => "J'y serai",
            self::No => "Je n'y serai pas",
            self::Maybe => 'Je ne sais pas encore',
        };
    }
}
