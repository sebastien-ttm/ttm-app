<?php

namespace App\Enum;

/** Présence d'une personne à une réunion du comité directeur : présente, excusée, ou invitée (non membre). */
enum BoardAttendance: string
{
    case Present = 'present';
    case Excused = 'excused';
    case Guest = 'guest';

    public function label(): string
    {
        return match ($this) {
            self::Present => 'Présent',
            self::Excused => 'Excusé',
            self::Guest => 'Invité',
        };
    }
}
