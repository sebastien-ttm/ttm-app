<?php

namespace App\Enum;

/**
 * Tag de catégorisation transverse sur du contenu (Article, Event, StaticPage).
 *
 * Distinct de `Profile` (qui cible un type de personne) — ici on étiquette
 * la NATURE du contenu pour décider quels comptes spéciaux peuvent le voir.
 *
 *   - EcoleTriathlon : contenu École de Triathlon (jeunes). Un user marqué
 *                      typeLicence='Dirigeant' ne voit QUE ce qui porte ce
 *                      tag (ou rien — un contenu sans tag reste public).
 *
 * Convention identique au trait AudienceAwareTrait :
 *   contentAudience vide [] = visible par tous (= public, non spécialisé).
 *   contentAudience non vide = ce contenu est tagué — il reste visible par
 *   les utilisateurs normaux ; il devient l'unique catégorie visible pour
 *   les comptes restreints (Dirigeant).
 */
enum ContentAudience: string
{
    case EcoleTriathlon = 'ecole_triathlon';

    public function label(): string
    {
        return match ($this) {
            self::EcoleTriathlon => 'École de Triathlon',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::EcoleTriathlon => '#16a34a',
        };
    }

    /** @return array<string, string>  ['École de Triathlon' => 'ecole_triathlon', ...] */
    public static function choices(): array
    {
        $out = [];
        foreach (self::cases() as $c) {
            $out[$c->label()] = $c->value;
        }
        return $out;
    }
}
