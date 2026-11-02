<?php

namespace App\Enum;

/**
 * Documents à fournir pour un adhérent jeune (mineur). Un seul document par type et par jeune : le nouvel envoi remplace
 * le précédent. Chaque type a un modèle à télécharger (voir officialTemplateUrl()).
 */
enum YouthDocumentType: string
{
    /** Fiche sanitaire de liaison (Cerfa 10008*02) : renseignements médicaux, vaccins, allergies, personnes à prévenir. */
    case HealthForm = 'health_form';
    /**
     * Autorisation de sortie du territoire d'un mineur (Cerfa 15646*01), avec la copie de la pièce d'identité du parent.
     * Rattachée à la participation à un stage (une par stage), pas aux documents permanents du jeune.
     */
    case ExitAuthorization = 'exit_authorization';
    /** Document du club : autorisation parentale pour que le jeune quitte seul l'entraînement et rentre seul chez lui. */
    case GoHomeAlone = 'go_home_alone';

    /** Document demandé pour chaque stage (et non une fois pour toutes). */
    public function isPerStage(): bool
    {
        return $this === self::ExitAuthorization;
    }

    /**
     * Documents permanents du jeune (fiche documents, vue d'ensemble du staff).
     *
     * @return list<self>
     */
    public static function permanentCases(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $t) => !$t->isPerStage()));
    }

    public function label(): string
    {
        return match ($this) {
            self::HealthForm => 'Fiche sanitaire',
            self::ExitAuthorization => 'Autorisation de sortie du territoire',
            self::GoHomeAlone => 'Autorisation de rentrer seul',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::HealthForm => '🩺',
            self::ExitAuthorization => '🛂',
            self::GoHomeAlone => '🏠',
        };
    }

    /**
     * Modèle officiel à remplir (formulaire Cerfa sur service-public.fr). null pour le document du club, dont le modèle
     * est généré par l'appli, pré-rempli au nom du jeune (voir YouthDocumentService::renderTemplate()).
     */
    public function officialTemplateUrl(): ?string
    {
        return match ($this) {
            self::HealthForm => 'https://www.formulaires.service-public.fr/gf/cerfa_10008.do',
            self::ExitAuthorization => 'https://www.formulaires.service-public.fr/gf/cerfa_15646.do',
            self::GoHomeAlone => null,
        };
    }
}
