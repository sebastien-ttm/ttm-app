<?php

namespace App\Service\Membership;

use App\Entity\User;
use App\Repository\TrainingSeasonRepository;
use App\Repository\UserSeasonMembershipRepository;

/**
 * Calcule le statut d'adhésion à afficher dans le profil mobile :
 *
 *  - « Adhérent 2025-2026 »                (adhérent licencié au club, saison en cours)
 *  - « Adhérent externe 2025-2026 »        (adhérent licencié dans un AUTRE club)
 *  - « Adhérent 2024-2025 » + needsRenewal (n'a pas renouvelé, période de grâce)
 *  - null                                   (compte externe non-licencié, aucun statut à afficher)
 *
 * Le libellé « externe » se réfère au SUBTYPE (club vs autre_club), pas
 * au type (adherent vs externe) : un adhérent externe = licencié FFTri
 * dans un autre club, avec un compte adhérent chez nous.
 */
class MembershipStatusResolver
{
    public function __construct(
        private readonly TrainingSeasonRepository $seasons,
        private readonly UserSeasonMembershipRepository $memberships,
    ) {
    }

    /**
     * @return array{label:string,season:string,needsRenewal:bool,isExternal:bool}|null
     */
    public function resolve(User $user): ?array
    {
        // Un compte externe non-licencié (parent d'adhérent, ami du club)
        // n'a pas de statut d'adhésion à afficher — subTypeLabel restera
        // utilisé côté client comme aujourd'hui.
        if (!$user->isAdherent()) {
            return null;
        }

        $currentSeason = $this->seasons->findCurrent();
        if ($currentSeason === null) {
            return null;
        }
        $currentLabel = (string) $currentSeason;
        $isExternal = $user->isLicencieAutreClub();
        $prefix = $isExternal ? 'Adhérent externe' : 'Adhérent';

        // Adhésion à jour pour la saison en cours.
        $current = $this->memberships->findOneByUserAndSeason($user, $currentSeason);
        if ($current !== null) {
            return [
                'label' => $prefix.' '.$currentLabel,
                'season' => $currentLabel,
                'needsRenewal' => false,
                'isExternal' => $isExternal,
            ];
        }

        // Pas d'adhésion pour la saison courante : période de grâce si
        // l'user a été licencié dans une saison antérieure. Sinon rien
        // (compte adhérent sans aucune adhésion historique — cas rare :
        // création manuelle, backfill incomplet).
        $last = $this->memberships->findLatestForUser($user);
        if ($last === null) {
            return null;
        }
        $lastLabel = (string) $last->getSeason();
        return [
            'label' => $prefix.' '.$lastLabel,
            'season' => $lastLabel,
            'needsRenewal' => true,
            'isExternal' => $isExternal,
        ];
    }
}
