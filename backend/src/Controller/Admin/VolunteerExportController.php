<?php

namespace App\Controller\Admin;

use App\Entity\VolunteerRole;
use App\Repository\VolunteerApplicationRepository;
use App\Repository\VolunteerCampaignRepository;
use App\Service\Volunteer\VolunteerPresenter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Export CSV d'une campagne de bénévolat (remplace le classeur pour le suivi) : une ligne par fiche avec les
 * personnes en place, puis une ligne par candidature. Campagne = ?campaign=ID, sinon la plus récente.
 */
#[IsGranted('ROLE_ADMIN')]
class VolunteerExportController extends AbstractController
{
    public function __construct(
        private readonly VolunteerCampaignRepository $campaigns,
        private readonly VolunteerApplicationRepository $applications,
        private readonly VolunteerPresenter $presenter,
    ) {
    }

    #[Route('/admin/volunteer/export.csv', name: 'admin_volunteer_export')]
    public function exportCsv(Request $request): StreamedResponse
    {
        $id = $request->query->getInt('campaign');
        $campaign = $id > 0
            ? $this->campaigns->find($id)
            : ($this->campaigns->findBy([], ['startsOn' => 'DESC'], 1)[0] ?? null);
        if ($campaign === null) {
            throw $this->createNotFoundException('Aucun événement de bénévolat.');
        }
        $applications = $this->applications->findForCampaign($campaign);

        $response = new StreamedResponse(function () use ($campaign, $applications): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, ['Fiches'], ';');
            fputcsv($out, ['Pôle', 'Type', 'Fiche', 'Publiée', 'Nb requis', 'En place', 'Candidatures en attente'], ';');
            foreach ($campaign->getPoles() as $pole) {
                foreach ($pole->getRoles() as $role) {
                    fputcsv($out, [
                        $pole->getName(),
                        $role->getKind() === VolunteerRole::KIND_FIELD ? 'Responsable terrain' : 'Activité',
                        $role->getName(),
                        $role->isPublished() ? 'oui' : 'non',
                        $role->getRequiredCount() ?? '',
                        implode(', ', $this->presenter->holders($role)['names']),
                        $role->getPendingCount(),
                    ], ';');
                }
            }

            fputcsv($out, [], ';');
            fputcsv($out, ['Candidatures'], ';');
            fputcsv($out, ['Pôle', 'Fiche', 'Adhérent', 'Téléphone', 'Email', 'Statut', 'Message', 'Le', 'Décidée par'], ';');
            foreach ($applications as $a) {
                $user = $a->getUser();
                fputcsv($out, [
                    $a->getPoleName(),
                    $a->getRole()->getName(),
                    $user->getFullName(),
                    $user->getTelephone() ?? '',
                    $user->getEmail(),
                    $a->getStatusLabel(),
                    $a->getMessage() ?? '',
                    $a->getCreatedAt()->format('d/m/Y H:i'),
                    $a->getDecidedBy()?->getFullName() ?? '',
                ], ';');
            }
            fclose($out);
        });

        $filename = sprintf('benevolat-%s-%s.csv', $campaign->getSlug(), (new \DateTimeImmutable())->format('Y-m-d'));
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="'.$filename.'"');

        return $response;
    }
}
