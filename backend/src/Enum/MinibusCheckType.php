<?php

namespace App\Enum;

/** État des lieux du minibus : au départ (avant de prendre la route) ou au retour (une fois le minibus rendu). */
enum MinibusCheckType: string
{
    case Departure = 'departure';
    case Return = 'return';

    public function label(): string
    {
        return match ($this) {
            self::Departure => 'État des lieux au départ',
            self::Return => 'État des lieux au retour',
        };
    }
}
