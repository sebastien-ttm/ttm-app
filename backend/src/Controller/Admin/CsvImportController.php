<?php

namespace App\Controller\Admin;

use App\Repository\TrainingSeasonRepository;
use App\Service\Csv\CsvImportService;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
class CsvImportController extends AbstractController
{
    public function __construct(
        private readonly CsvImportService $importer,
        private readonly TrainingSeasonRepository $seasons,
        private readonly AdminUrlGenerator $adminUrlGenerator,
    ) {
    }

    #[Route('/admin/csv-import', name: 'admin_csv_import')]
    public function index(Request $request): Response
    {
        // Le template étend @EasyAdmin/page/content.html.twig qui appelle
        // `ea()` — celui-ci retourne null quand la requête n'a pas les
        // query params du dashboard. Sur un GET direct (bookmark, F5 après
        // redirect) ou un POST « brut », on redirige vers l'URL enrichie
        // par AdminUrlGenerator, qui embarque les params attendus.
        $adminUrl = $this->adminUrlGenerator
            ->unsetAll()
            ->setRoute('admin_csv_import')
            ->generateUrl();
        if ($request->query->get('dashboardControllerFqcn') === null && $request->isMethod('GET')) {
            return $this->redirect($adminUrl);
        }

        $result = null;
        $error = null;

        // Liste des saisons proposées dans le sélecteur (récente d'abord).
        $seasons = $this->seasons->createQueryBuilder('s')
            ->orderBy('s.startsAt', 'DESC')
            ->getQuery()->getResult();
        $currentSeason = $this->seasons->findCurrent();

        if ($request->isMethod('POST')) {
            /** @var UploadedFile|null $file */
            $file = $request->files->get('csv_file');
            $seasonId = (int) $request->request->get('season_id', 0);
            $season = $seasonId > 0 ? $this->seasons->find($seasonId) : null;

            if ($file === null) {
                $error = 'Aucun fichier sélectionné.';
            } elseif (!in_array($file->getClientOriginalExtension(), ['csv', 'txt'], true)) {
                $error = 'Le fichier doit avoir l\'extension .csv';
            } elseif ($season === null) {
                $error = 'Vous devez sélectionner la saison d\'adhésion associée à cet import.';
            } else {
                $tmpPath = $file->getRealPath();
                $delimiter = (string) ($request->request->get('delimiter') ?? ',');
                $sendWelcome = (bool) $request->request->get('send_welcome', '1');
                // Deux boutons de soumission :
                //   name=action, value=dry_run  → simulation (rollback DB, aucun email)
                //   name=action, value=commit   → import réel
                // Défaut : dry-run, pour éviter tout import accidentel.
                $action = (string) $request->request->get('action', 'dry_run');
                $dryRun = $action !== 'commit';

                try {
                    $result = $this->importer->import($tmpPath, $sendWelcome, $delimiter, $season, $dryRun);
                } catch (\Throwable $e) {
                    $error = 'Erreur lors de l\'import : '.$e->getMessage();
                }
            }
        }

        return $this->render('admin/csv_import.html.twig', [
            'result' => $result,
            'error' => $error,
            'seasons' => $seasons,
            'currentSeasonId' => $currentSeason?->getId(),
            // URL avec les params EA — utilisée par le <form action="…">
            // pour que le POST ré-atterrisse sur une requête avec contexte
            // dashboard, quel que soit le chemin d'arrivée initial.
            'formAction' => $adminUrl,
        ]);
    }
}
