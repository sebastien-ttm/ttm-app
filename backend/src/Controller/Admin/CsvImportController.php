<?php

namespace App\Controller\Admin;

use App\Repository\TrainingSeasonRepository;
use App\Service\Csv\CsvImportService;
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
    ) {
    }

    #[Route('/admin/csv-import', name: 'admin_csv_import')]
    public function index(Request $request): Response
    {
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

                try {
                    $result = $this->importer->import($tmpPath, $sendWelcome, $delimiter, $season);
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
        ]);
    }
}
