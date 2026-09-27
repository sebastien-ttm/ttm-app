<?php

namespace App\Enum;

/**
 * Type d'une course proposée par un adhérent (onglet Social).
 */
enum RaceType: string
{
    case Triathlon = 'triathlon';
    case Trail = 'trail';
    case Route = 'route';
    case Cyclosportive = 'cyclosportive';
    case EauLibre = 'eau_libre';
    case Autre = 'autre';

    public function label(): string
    {
        return match ($this) {
            self::Triathlon => 'Triathlon',
            self::Trail => 'Trail',
            self::Route => 'Course à pied sur route',
            self::Cyclosportive => 'Cyclosportive',
            self::EauLibre => 'Eau libre',
            self::Autre => 'Autre',
        };
    }

    /** @return array<string, string> libellé => valeur (ChoiceField EasyAdmin) */
    public static function choices(): array
    {
        $out = [];
        foreach (self::cases() as $c) {
            $out[$c->label()] = $c->value;
        }
        return $out;
    }
}
