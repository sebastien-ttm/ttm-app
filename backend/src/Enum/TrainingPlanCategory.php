<?php

namespace App\Enum;

enum TrainingPlanCategory: string
{
    case General = 'general';
    case LongueDistance = 'longue_distance';

    public function label(): string
    {
        return match ($this) {
            self::General => 'Général',
            self::LongueDistance => 'Longue distance',
        };
    }

    /**
     * Suffix to append next to the plan title for adherents.
     * Empty string for the default category (no parenthetical needed).
     */
    public function publicSuffix(): string
    {
        return match ($this) {
            self::General => '',
            self::LongueDistance => '(longue distance)',
        };
    }
}
