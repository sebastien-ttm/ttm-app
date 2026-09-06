<?php

namespace App\Enum;

/**
 * Portée de destinataire d'un UserMessage :
 *  - Club          : adressé « au club » → visible par tous les admins.
 *  - Trainer       : adressé à UN entraîneur nommé (recipient renseigné).
 *  - AllTrainers   : adressé à TOUS les entraîneurs actifs simultanément.
 *
 * Le champ recipient de l'entité UserMessage n'est renseigné QUE pour Trainer.
 */
enum MessageScope: string
{
    case Club = 'club';
    case Trainer = 'trainer';
    case AllTrainers = 'all_trainers';

    public function label(): string
    {
        return match ($this) {
            self::Club => 'Le club',
            self::Trainer => 'Entraîneur',
            self::AllTrainers => 'Tous les entraîneurs',
        };
    }
}
