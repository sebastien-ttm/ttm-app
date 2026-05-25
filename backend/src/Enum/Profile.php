<?php

namespace App\Enum;

/**
 * Profils d'utilisateur — un adhérent peut en avoir plusieurs.
 *
 *  - Jeune / Senior  : auto-assignés à l'import CSV selon l'année des 19 ans
 *                      (cohabite avec UserCategory pour la rétrocompat).
 *  - U25             : sous-catégorie senior, sélectionnable à la main.
 *  - Parent          : peut être posé sur un adhérent OU un compte parent
 *                      autonome (cf. phase 3).
 *  - Encadrant       : adhérent qui encadre des créneaux. Donne accès
 *                      à une page de saisie de présence (cf. phase 2).
 *
 * Les profils Entraineur (ROLE_COACH) et Admin (ROLE_ADMIN) restent
 * gérés via le tableau roles[] de User — pas besoin de les dupliquer ici.
 */
enum Profile: string
{
    case Jeune = 'jeune';
    case Senior = 'senior';
    case U25 = 'u25';
    case Parent = 'parent';
    case Encadrant = 'encadrant';

    public function label(): string
    {
        return match ($this) {
            self::Jeune => 'Jeune',
            self::Senior => 'Sénior',
            self::U25 => 'U25',
            self::Parent => 'Parent',
            self::Encadrant => 'Encadrant',
        };
    }

    /** Couleur badge (admin + mobile). */
    public function color(): string
    {
        return match ($this) {
            self::Jeune => '#16a34a',
            self::Senior => '#1d4ed8',
            self::U25 => '#7c3aed',
            self::Parent => '#ea580c',
            self::Encadrant => '#dc2626',
        };
    }

    /** @return array<string, string>  ['Jeune' => 'jeune', ...] pour ChoiceField */
    public static function choices(): array
    {
        $out = [];
        foreach (self::cases() as $c) {
            $out[$c->label()] = $c->value;
        }
        return $out;
    }

    /** @return array<string, self>  ['Jeune' => Profile::Jeune, ...] pour Symfony Form avec enum */
    public static function enumChoices(): array
    {
        $out = [];
        foreach (self::cases() as $c) {
            $out[$c->label()] = $c;
        }
        return $out;
    }

    /**
     * Calcule le profil principal (Jeune | Senior) à partir d'une date de naissance,
     * selon la règle FFTri : au plus 18 ans dans l'année courante = Jeune.
     */
    public static function principalFromBirthDate(\DateTimeInterface $birthDate, ?\DateTimeInterface $now = null): self
    {
        $now ??= new \DateTimeImmutable('today');
        $ageInYear = (int) $now->format('Y') - (int) $birthDate->format('Y');
        return $ageInYear <= 18 ? self::Jeune : self::Senior;
    }
}
