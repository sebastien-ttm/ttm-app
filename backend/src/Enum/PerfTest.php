<?php

namespace App\Enum;

/**
 * Épreuves de test chronométrées par les entraîneurs.
 */
enum PerfTest: string
{
    case Run1500 = 'run_1500';
    case Swim400 = 'swim_400';
    case BikeClimb2k = 'bike_climb_2k';

    public function label(): string
    {
        return match ($this) {
            self::Run1500 => '1500 m course à pied',
            self::Swim400 => '400 m natation',
            self::BikeClimb2k => 'Montée 2 km vélo',
        };
    }

    /** Libellé court (sous-onglets de l'appli). */
    public function shortLabel(): string
    {
        return match ($this) {
            self::Run1500 => '1500 m',
            self::Swim400 => '400 m nage',
            self::BikeClimb2k => 'Montée 2 km',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Run1500 => '🏃',
            self::Swim400 => '🏊',
            self::BikeClimb2k => '🚴',
        };
    }

    /**
     * Fourchette de temps plausible, en secondes [min, max] — sert à repérer
     * un temps mal lu à l'import (ex : « 5:42 » interprété comme 5 h 42).
     *
     * @return array{int, int}
     */
    public function plausibleSeconds(): array
    {
        return match ($this) {
            self::Run1500 => [180, 1500],
            self::Swim400 => [180, 1500],
            self::BikeClimb2k => [60, 2400],
        };
    }

    /** Seule la natation dépend de la longueur du bassin (25 / 50 m). */
    public function needsPoolLength(): bool
    {
        return $this === self::Swim400;
    }

    /** @return array<string, string> ['1500 m course à pied' => 'run_1500', ...] pour ChoiceField */
    public static function choices(): array
    {
        $out = [];
        foreach (self::cases() as $c) {
            $out[$c->label()] = $c->value;
        }
        return $out;
    }
}
