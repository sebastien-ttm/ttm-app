<?php

namespace App\Enum;

enum EventType: string
{
    case Course = 'course';
    case Stage = 'stage';
    case Entrainement = 'entrainement';
    case Social = 'social';

    public function label(): string
    {
        return match ($this) {
            self::Course => 'Course / Compétition',
            self::Stage => 'Stage',
            self::Entrainement => 'Entraînement exceptionnel',
            self::Social => 'Événement social',
        };
    }

    public function defaultColor(): string
    {
        return match ($this) {
            self::Course => '#D32F2F',       // rouge club
            self::Stage => '#1976D2',
            self::Entrainement => '#388E3C',
            self::Social => '#7B1FA2',
        };
    }
}
