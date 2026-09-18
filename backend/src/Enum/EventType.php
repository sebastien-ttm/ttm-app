<?php

namespace App\Enum;

/**
 * Types d'événement calendrier. Chaque type porte sa propre couleur
 * (palette club) — plus de color picker dans le CRUD : la couleur est
 * dérivée du type, garantissant une charte cohérente.
 *
 * Ordre d'affichage retenu côté admin : Stage, Compétition, Événement
 * convivial, Bénévolat, Journée cohésion, Tenues (cf. adminChoices()).
 *
 * `Entrainement` est conservé pour la rétro-compatibilité des lignes
 * existantes (rows en base valorisées 'entrainement') mais n'est plus
 * proposé à la saisie.
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
            self::Social => '#7B1FA2',            // violet — convivial
            self::Organisation => '#F57C00',      // orange — bénévolat / logistique
            self::JourneeCohesion => '#00838F',   // teal — cohésion / vie du club
            self::Tenues => '#5D4037',            // brun — équipement / textile
        };
    }

    /** Alias rétro-compat — anciens callers de defaultColor(). */
    public function defaultColor(): string
    {
        return $this->color();
    }

    /**
     * Types proposés à la saisie dans l'admin, dans l'ordre voulu.
     * `Entrainement` en est exclu : conservé uniquement pour lire les
     * anciennes lignes.
     *
     * @return list<self>
     */
    public static function adminChoices(): array
    {
        return [
            self::Stage,
            self::Course,
            self::Social,
            self::Organisation,
            self::JourneeCohesion,
            self::Tenues,
        ];
    }
}
