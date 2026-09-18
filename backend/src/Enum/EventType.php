<?php

namespace App\Enum;

/**
 * Types d'événement calendrier. Chaque type porte sa propre couleur
 * (palette club) — plus de color picker dans le CRUD : la couleur est
 * dérivée du type, garantissant une charte cohérente.
 *
 * Ordre d'affichage retenu côté admin : Stage, Compétition, Cohésion,
 * Bénévolat, Journée, Tenues, Informations (cf. adminChoices()).
 *
 * Les cas `Entrainement`, `Social`, `JourneeCohesion` sont conservés
 * pour la rétro-compatibilité des lignes existantes (rows en base
 * valorisées 'entrainement' / 'social' / 'journee_cohesion') mais ne
 * sont plus proposés à la saisie. Un admin qui édite un événement
 * legacy peut basculer vers un des nouveaux types via le dropdown
 * enrichi côté PAGE_EDIT (cf. EventCrudController).
 */
enum EventType: string
{
    case Course = 'course';
    case Stage = 'stage';
    case Entrainement = 'entrainement';
    case Social = 'social';
    case Organisation = 'organisation';
    case JourneeCohesion = 'journee_cohesion';
    case Tenues = 'tenues';
    case Cohesion = 'cohesion';
    case Journee = 'journee';
    case Informations = 'informations';

    public function label(): string
    {
        return match ($this) {
            self::Course => 'Compétition',
            self::Stage => 'Stage',
            self::Entrainement => 'Entraînement exceptionnel',
            self::Social => 'Événement convivial',
            self::Organisation => 'Bénévolat',
            self::JourneeCohesion => 'Journée cohésion',
            self::Tenues => 'Tenues',
            self::Cohesion => 'Cohésion',
            self::Journee => 'Journée',
            self::Informations => 'Informations',
        };
    }

    /**
     * Couleur de la pastille calendrier pour ce type.
     * Palette pensée pour rester lisible sur fond clair ET avec texte blanc
     * (utilisée comme background des date-box dans UpcomingEvents).
     */
    public function color(): string
    {
        return match ($this) {
            self::Course => '#D32F2F',            // rouge — compétitions
            self::Stage => '#1976D2',             // bleu — stages
            self::Entrainement => '#388E3C',      // vert — séances ponctuelles (legacy)
            self::Social => '#7B1FA2',            // violet — convivial (legacy)
            self::Organisation => '#F57C00',      // orange — bénévolat / logistique
            self::JourneeCohesion => '#00838F',   // teal — cohésion (legacy)
            self::Tenues => '#5D4037',            // brun — équipement / textile
            self::Cohesion => '#00838F',          // teal — cohésion
            self::Journee => '#5E35B1',           // violet profond — journée club
            self::Informations => '#455A64',      // blue-grey — informations
        };
    }

    /** Alias rétro-compat — anciens callers de defaultColor(). */
    public function defaultColor(): string
    {
        return $this->color();
    }

    /**
     * Types proposés à la saisie côté admin, dans l'ordre voulu.
     * Les cases legacy (Entrainement, Social, JourneeCohesion) en sont
     * exclus — ils restent lisibles pour les événements existants et
     * migrables via la page d'édition (qui, elle, inclut tous les cases).
     *
     * @return list<self>
     */
    public static function adminChoices(): array
    {
        return [
            self::Stage,
            self::Course,
            self::Cohesion,
            self::Organisation,
            self::Journee,
            self::Tenues,
            self::Informations,
        ];
    }
}
