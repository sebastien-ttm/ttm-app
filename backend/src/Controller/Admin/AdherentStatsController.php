<?php

namespace App\Controller\Admin;

use App\Entity\TrainingSeason;
use App\Entity\UserSeasonMembership;
use App\Repository\TrainingSeasonRepository;
use App\Repository\UserSeasonMembershipRepository;
use App\Service\Csv\CsvImportService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Statistiques adhérents par saison :
 *  - effectif total
 *  - répartition par type de licence (snapshot au moment de l'import)
 *  - répartition par sexe
 *  - âge moyen calculé à la date de rattachement à la saison
 *    (date de début de saison, sinon date d'import)
 *
 * Utilise UserSeasonMembership : chaque saison compte donc les adhérents
 * qui ONT effectivement une adhésion enregistrée pour elle (backfill inclus).
 */
#[IsGranted('ROLE_ADMIN')]
class AdherentStatsController extends AbstractController
{
    public function __construct(
        private readonly TrainingSeasonRepository $seasons,
        private readonly UserSeasonMembershipRepository $memberships,
    ) {
    }

    #[Route('/admin/adherents/stats', name: 'admin_adherents_stats')]
    public function index(): Response
    {
        $allSeasons = $this->seasons->createQueryBuilder('s')
            ->orderBy('s.startsAt', 'DESC')
            ->getQuery()->getResult();

        $sections = [];
        foreach ($allSeasons as $season) {
            /** @var TrainingSeason $season */
            $rows = $this->memberships->findBySeason($season);
            $sections[] = $this->summarize($season, $rows);
        }

        return $this->render('admin/adherents_stats.html.twig', [
            'sections' => $sections,
        ]);
    }

    /**
     * @param list<UserSeasonMembership> $rows
     * @return array<string, mixed>
     */
    private function summarize(TrainingSeason $season, array $rows): array
    {
        $total = count($rows);
        $byType = ['Compétition' => 0, 'Loisir' => 0, 'Dirigeant' => 0, 'Non renseigné' => 0];
        $bySex = ['Hommes' => 0, 'Femmes' => 0, 'Non renseigné' => 0];
        // Âge moyen calculé au début de saison (fallback : date d'import
        // de la membership si la saison n'a pas de startsAt).
        $ageSum = 0.0;
        $ageCount = 0;

        foreach ($rows as $m) {
            $u = $m->getUser();
            // Type licence : snapshot pris à l'import (spécifique à la
            // saison — un adhérent peut avoir Loisir une saison et
            // Compétition la suivante). Re-normalisation défensive au
            // cas où le snapshot serait une valeur brute ancienne.
            $raw = $m->getTypeLicence();
            $type = $raw !== null ? (CsvImportService::normalizeTypeLicence($raw) ?? $raw) : null;
            $type = in_array($type, ['Compétition', 'Loisir', 'Dirigeant'], true) ? $type : 'Non renseigné';
            $byType[$type]++;

            // Sexe (sur User — pas snapshoté, prendre la valeur courante)
            $sex = $u->getSexe();
            if ($sex === 'm') { $bySex['Hommes']++; }
            elseif ($sex === 'f') { $bySex['Femmes']++; }
            else { $bySex['Non renseigné']++; }

            // Âge à la date de rattachement
            $birth = $u->getDateNaissance();
            if ($birth !== null) {
                $ref = $season->getStartsAt() ?? $m->getImportedAt();
                $years = $ref->diff($birth)->y;
                $ageSum += $years;
                $ageCount++;
            }
        }

        $avgAge = $ageCount > 0 ? round($ageSum / $ageCount, 1) : null;

        return [
            'season' => $season,
            'total' => $total,
            'byType' => $byType,
            'bySex' => $bySex,
            'avgAge' => $avgAge,
            'avgAgeSampleSize' => $ageCount,
        ];
    }
}
