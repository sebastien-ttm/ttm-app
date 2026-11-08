<?php

namespace App\Enum;

/**
 * Pôles de rattachement d'un point à l'ordre du jour du comité directeur, dans l'ordre du compte rendu
 * (repris du modèle CODIR_2026_09_23.xlsx).
 */
enum BoardPole: string
{
    case Partenaires = 'partenaires';
    case Subventions = 'subventions';
    case Organisations = 'organisations';
    case Social = 'social';
    case Rh = 'rh';
    case Stage = 'stage';
    case Tenues = 'tenues';
    case Entrainements = 'entrainements';
    case Debutants = 'debutants';
    case Equipes = 'equipes';
    case LongueDistance = 'longue_distance';
    case CommunicationExterne = 'communication_externe';
    case CommunicationInterne = 'communication_interne';
    case Adhesions = 'adhesions';
    case Tresorerie = 'tresorerie';
    case OutilsNumeriques = 'outils_numeriques';
    case Divers = 'divers';

    public function label(): string
    {
        return match ($this) {
            self::Partenaires => 'Partenaires',
            self::Subventions => 'Subventions',
            self::Organisations => 'Organisations',
            self::Social => 'Social',
            self::Rh => 'RH',
            self::Stage => 'Stage',
            self::Tenues => 'Tenues',
            self::Entrainements => 'Entraînements',
            self::Debutants => 'Débutants',
            self::Equipes => 'Équipes',
            self::LongueDistance => 'Longue distance',
            self::CommunicationExterne => 'Communication externe',
            self::CommunicationInterne => 'Communication interne',
            self::Adhesions => 'Adhésions',
            self::Tresorerie => 'Trésorerie',
            self::OutilsNumeriques => 'Outils numériques',
            self::Divers => 'Divers',
        };
    }

    /** Rang d'affichage (ordre de déclaration). */
    public function order(): int
    {
        return (int) array_search($this, self::cases(), true);
    }

    /** @return array<string, string>  ['Partenaires' => 'partenaires', ...] pour ChoiceField / select */
    public static function choices(): array
    {
        $out = [];
        foreach (self::cases() as $c) {
            $out[$c->label()] = $c->value;
        }
        return $out;
    }
}
