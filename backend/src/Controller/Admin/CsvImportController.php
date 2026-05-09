<?php

namespace App\Controller\Admin;

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
    public function __construct(private readonly CsvImportService $importer)
    {
    }

    #[Route('/admin/csv-import', name: 'admin_csv_import')]
    public function index(Request $request): Response
    {
        $result = null;
        $error = null;

        if ($request->isMethod('POST')) {
            /** @var UploadedFile|null $file */
            $file = $request->files->get('csv_file');

            if ($file === null) {
                $error = 'Aucun fichier sélectionné.';
            } elseif (!in_array($file->getClientOriginalExtension(), ['csv', 'txt'], true)) {
                $error = 'Le fichier doit avoir l\'extension .csv';
            } else {
                $tmpPath = $file->getRealPath();
                $delimiter = (string) ($request->request->get('delimiter') ?? ',');
                $sendWelcome = (bool) $request->request->get('send_welcome', '1');

                try {
                    $result = $this->importer->import($tmpPath, $sendWelcome, $delimiter);
                } catch (\Throwable $e) {
                    $error = 'Erreur lors de l\'import : '.$e->getMessage();
                }
            }
        }

        return $this->render('admin/csv_import.html.twig', [
            'result' => $result,
            'error' => $error,
        ]);
    }
}
