<?php

namespace App\Enum;

enum UserCategory: string
{
    case Senior = 'senior';
    case Jeune = 'jeune';

    public function label(): string
    {
        return match ($this) {
            self::Senior => 'Sénior',
            self::Jeune => 'Jeune',
        };
    }

    public static function fromCsv(string $raw): self
    {
        $normalized = mb_strtolower(trim($raw), 'UTF-8');
        return match ($normalized) {
            'senior', 'sénior', 'séniors', 'seniors' => self::Senior,
            'jeune', 'jeunes' => self::Jeune,
            default => self::Senior,
        };
    }
}
