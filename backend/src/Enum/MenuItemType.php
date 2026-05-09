<?php

namespace App\Enum;

enum MenuItemType: string
{
    case Feed = 'feed';
    case Training = 'training';
    case Calendar = 'calendar';
    case Page = 'page';
    case External = 'external';

    public function label(): string
    {
        return match ($this) {
            self::Feed => "Flux d'actualités",
            self::Training => "Plans d'entraînement",
            self::Calendar => 'Calendrier',
            self::Page => 'Page statique',
            self::External => 'Lien externe',
        };
    }

    public function requiresTarget(): bool
    {
        return $this === self::Page || $this === self::External;
    }
}
