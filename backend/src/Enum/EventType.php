<?php

namespace App\Enum;

/**
 * Types d'événement calendrier. Chaque type porte sa propre couleur
 * (palette club) — plus de color picker dans le CRUD : la couleur est
 * dérivée du type, garantissant une charte cohérente.
 */
enum EventType: string
{
    case Course = 'course';
    case Stage = 'stage';
    case Entrainement = 'entrainement';
    case Social = 'social';
    case Organisation = 'organisation';

    public function label(): string
    {
        return match ($this) {
            self::Course => 'Compétition',
            self::Stage => 'Stage',
            self::Entrainement => 'Entraînement exceptionnel',
            self::Social => 'Événement social',
            self::Organisation => 'Organisation',
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
            self::Course => '#D32F2F',        // rouge club — événements compétitifs
            self::Stage => '#1976D2',         // bleu — stages multi-jours
            self::Entrainement => '#388E3C',  // vert — séances ponctuelles
            self::Social => '#7B1FA2',        // violet — convivial / vie associative
            self::Organisation => '#F57C00',  // orange — réunions / logistique club
        };
    }

    /** Alias rétro-compat — anciens callers de defaultColor(). */
    public function defaultColor(): string
    {
        return $this->color();
    }
}
