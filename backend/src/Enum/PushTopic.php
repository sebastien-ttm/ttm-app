<?php

namespace App\Enum;

/**
 * Sujets de notification push que l'adhérent peut activer / désactiver depuis son profil
 * (User::$pushDisabledTopics). Tous sont activés par défaut : on stocke uniquement ceux qu'il coupe.
 */
enum PushTopic: string
{
    case Event = 'event';
    case Article = 'article';
    case TrainingPlan = 'training_plan';
    case ClubPage = 'club_page';
    case Survey = 'survey';
    case PhotoAlbum = 'photo_album';
    case Race = 'race';
    case Marketplace = 'marketplace';
    case Bib = 'bib';

    public function label(): string
    {
        return match ($this) {
            self::Event => 'Nouvel événement',
            self::Article => 'Nouvel article',
            self::TrainingPlan => 'Nouveau plan d\'entraînement',
            self::ClubPage => 'Nouvelle page du club',
            self::Survey => 'Nouveau sondage',
            self::PhotoAlbum => 'Nouvel album photo',
            self::Race => 'Compétition à faire ensemble',
            self::Marketplace => 'Nouvelle annonce d\'occasion',
            self::Bib => 'Nouveau dossard à céder',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Event => 'Un événement est ajouté au calendrier du club.',
            self::Article => 'Un article est publié dans les actualités.',
            self::TrainingPlan => 'Un plan d\'entraînement est publié par les entraîneurs.',
            self::ClubPage => 'Une nouvelle page est publiée dans les informations du club.',
            self::Survey => 'Un nouveau sondage est ouvert.',
            self::PhotoAlbum => 'Un album photo est créé dans la galerie.',
            self::Race => 'Un adhérent propose une compétition à faire ensemble.',
            self::Marketplace => 'Une annonce est déposée dans la bourse aux équipements.',
            self::Bib => 'Un adhérent propose un dossard à céder.',
        };
    }

    /** @return list<string> valeurs de tous les sujets, dans l'ordre d'affichage */
    public static function values(): array
    {
        return array_map(static fn (self $t) => $t->value, self::cases());
    }
}
