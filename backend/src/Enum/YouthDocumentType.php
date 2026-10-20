<?php

namespace App\Enum;

/**
 * Documents officiels à fournir pour un adhérent jeune (mineur). Un seul document par type et par jeune : le nouvel envoi
 * remplace le précédent.
 */
enum YouthDocumentType: string
{
    /** Fiche sanitaire de liaison (Cerfa 10008*02) : renseignements médicaux, vaccins, allergies, personnes à prévenir. */
    case HealthForm = 'health_form';
    /** Autorisation de sortie du territoire d'un mineur (Cerfa 15646*01), avec la copie de la pièce d'identité du parent. */
    case ExitAuthorization = 'exit_authorization';

    public function label(): string
    {
        return match ($this) {
            self::HealthForm => 'Fiche sanitaire',
            self::ExitAuthorization => 'Autorisation de sortie du territoire',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::HealthForm => '🩺',
            self::ExitAuthorization => '🛂',
        };
    }
}
