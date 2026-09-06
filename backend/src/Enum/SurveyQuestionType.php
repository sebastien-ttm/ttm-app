<?php

namespace App\Enum;

/**
 * Types de réponse supportés dans un sondage :
 *  - ShortText     : réponse libre courte (input simple)
 *  - LongText      : réponse libre longue (textarea)
 *  - SingleChoice  : radio, une seule option parmi la liste
 *  - MultiChoice   : cases à cocher, plusieurs options possibles
 */
enum SurveyQuestionType: string
{
    case ShortText = 'short_text';
    case LongText = 'long_text';
    case SingleChoice = 'single_choice';
    case MultiChoice = 'multi_choice';

    public function label(): string
    {
        return match ($this) {
            self::ShortText => 'Texte court',
            self::LongText => 'Texte long',
            self::SingleChoice => 'Choix unique',
            self::MultiChoice => 'Choix multiple',
        };
    }

    public function needsOptions(): bool
    {
        return $this === self::SingleChoice || $this === self::MultiChoice;
    }
}
