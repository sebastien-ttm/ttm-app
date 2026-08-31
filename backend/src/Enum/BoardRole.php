<?php

namespace App\Enum;

/**
 * Rôle au sein du Comité Directeur (CoDir) du club. Exclusif :
 * un adhérent a AU PLUS un rôle CoDir. Les 3 premiers rôles
 * composent le Bureau ; les Membres CoDir sont les autres élus.
 * Un adhérent sans rôle n'apparait pas dans la page trombinoscope
 * comité (sauf s'il est Entraîneur, section dédiée).
 */
enum BoardRole: string
{
    case President = 'president';
    case Tresorier = 'tresorier';
    case Secretaire = 'secretaire';
    case MembreCoDir = 'membre_codir';

    public function label(): string
    {
        return match ($this) {
            self::President => 'Président',
            self::Tresorier => 'Trésorier',
            self::Secretaire => 'Secrétaire',
            self::MembreCoDir => 'Membre du CoDir',
        };
    }

    /** Ordre d'affichage dans le trombinoscope (rang décroissant). */
    public function displayOrder(): int
    {
        return match ($this) {
            self::President => 0,
            self::Tresorier => 1,
            self::Secretaire => 2,
            self::MembreCoDir => 10,
        };
    }

    public function isBureau(): bool
    {
        return $this !== self::MembreCoDir;
    }

    /** @return array<string, string>  ['Président' => 'president', ...] pour ChoiceField */
    public static function choices(): array
    {
        $out = [];
        foreach (self::cases() as $c) {
            $out[$c->label()] = $c->value;
        }
        return $out;
    }
}
