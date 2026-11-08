<?php

namespace App\Enum;

/** Nature d'un point à l'ordre du jour du comité directeur. */
enum BoardItemType: string
{
    case Information = 'information';
    case Sujet = 'sujet';
    case Question = 'question';

    public function label(): string
    {
        return match ($this) {
            self::Information => 'Information',
            self::Sujet => 'Sujet',
            self::Question => 'Question',
        };
    }

    /** @return array<string, string> */
    public static function choices(): array
    {
        $out = [];
        foreach (self::cases() as $c) {
            $out[$c->label()] = $c->value;
        }
        return $out;
    }
}
