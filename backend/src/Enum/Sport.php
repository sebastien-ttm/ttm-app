<?php

namespace App\Enum;

enum Sport: string
{
    case Natation = 'natation';
    case Velo = 'velo';
    case Course = 'course';
    case Multi = 'multi';
    case Renfo = 'renfo';
    case Autre = 'autre';

    public function label(): string
    {
        return match ($this) {
            self::Natation => 'Natation',
            self::Velo => 'Vélo',
            self::Course => 'Course à pied',
            self::Multi => 'Multi-sports',
            self::Renfo => 'Renforcement',
            self::Autre => 'Autre',
        };
    }

    /** Emoji utilisé côté mobile pour identifier rapidement le créneau. */
    public function icon(): string
    {
        return match ($this) {
            self::Natation => '🏊',
            self::Velo => '🚴',
            self::Course => '🏃',
            self::Multi => '🔁',
            self::Renfo => '💪',
            self::Autre => '🏅',
        };
    }

    /** Couleur d'accent pour l'UI (badge, bordure). */
    public function color(): string
    {
        return match ($this) {
            self::Natation => '#0284c7', // bleu eau
            self::Velo => '#16a34a',     // vert
            self::Course => '#ea580c',   // orange
            self::Multi => '#7c3aed',    // violet
            self::Renfo => '#dc2626',    // rouge
            self::Autre => '#6b7280',    // gris
        };
    }

    /** @return array<string, string>  ['natation' => 'Natation', ...] */
    public static function choices(): array
    {
        $out = [];
        foreach (self::cases() as $c) {
            $out[$c->label()] = $c->value;
        }
        return $out;
    }
}
