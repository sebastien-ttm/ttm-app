<?php

namespace App\Enum;

/**
 * Catégorie d'un UserMessage — précise l'intention de l'expéditeur
 * pour permettre au destinataire de trier rapidement sa boîte :
 *
 *  - General     : question / demande libre (défaut)
 *  - Bug         : « Ça ne marche pas / j'ai rencontré un problème »
 *  - Improvement : « J'aimerais qu'on ajoute / améliore… »
 *  - HelpOffer   : « Je propose mon aide au club »
 *
 * Les 3 dernières sont posées par des boutons dédiés côté mobile
 * (adressage automatique au club) ; General couvre le composer libre.
 */
enum MessageCategory: string
{
    case General = 'general';
    case Bug = 'bug';
    case Improvement = 'improvement';
    case HelpOffer = 'help_offer';

    public function label(): string
    {
        return match ($this) {
            self::General => 'Message',
            self::Bug => 'Bug appli',
            self::Improvement => 'Idée d\'amélioration',
            self::HelpOffer => 'Proposition d\'aide',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::General => '💬',
            self::Bug => '🐞',
            self::Improvement => '💡',
            self::HelpOffer => '🤝',
        };
    }
}
