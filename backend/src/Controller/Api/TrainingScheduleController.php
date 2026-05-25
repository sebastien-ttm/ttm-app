<?php

namespace App\Controller\Api;

use App\Entity\TrainingPlan;
use App\Repository\TrainingPlanRepository;
use App\Repository\TrainingSlotAttachmentRepository;
use App\Service\Serializer\ApiSerializer;
use App\Service\Training\AttachmentService;
use App\Service\Training\WeeklyScheduleService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * GET /api/training-schedule?week=YYYY-MM-DD
 *
 * Renvoie pour une semaine donnée :
 *  - les créneaux (semaine type + overrides + occasionnels, ordonnés)
 *  - les plans d'entraînement (PDF) associés à cette semaine
 *  - les métadonnées de la semaine (libellé, dates)
 *
 * Le paramètre "week" accepte n'importe quelle date de la semaine cible
 * (l'API snappe sur le lundi). Par défaut : semaine en cours.
 *
 * Politique : pour les adhérents (ROLE_USER), seules les semaines courante
 * et futures sont accessibles. Les coachs/admins peuvent consulter le passé.
 */
#[IsGranted('ROLE_USER')]
class TrainingScheduleController extends AbstractController
{
    public function __construct(
        private readonly WeeklyScheduleService $schedule,
        private readonly TrainingPlanRepository $plans,
        private readonly TrainingSlotAttachmentRepository $attachments,
        private readonly AttachmentService $attachmentService,
        private readonly ApiSerializer $serializer,
    ) {
    }

    #[Route('/api/training-schedule', methods: ['GET'])]
    public function week(Request $request): JsonResponse
    {
        $weekParam = (string) $request->query->get('week', '');
        try {
            $weekDate = $weekParam !== ''
                ? new \DateTimeImmutable($weekParam)
                : new \DateTimeImmutable('today');
        } catch (\Exception) {
            return new JsonResponse(
                ['error' => 'Paramètre "week" invalide (format attendu : YYYY-MM-DD).'],
                Response::HTTP_BAD_REQUEST,
            );
        }

        $monday = WeeklyScheduleService::snapToMonday($weekDate);

        // Pour un simple adhérent : interdit le passé (les coachs/admins peuvent).
        if (!$this->isGranted('ROLE_ADMIN')) {
            $thisMonday = WeeklyScheduleService::snapToMonday(new \DateTimeImmutable('today'));
            if ($monday < $thisMonday) {
                return new JsonResponse(
                    ['error' => 'Les semaines passées ne sont pas accessibles.'],
                    Response::HTTP_FORBIDDEN,
                );
            }
        }

        /** @var \App\Entity\User $viewer */
        $viewer = $this->getUser();
        $slots = $this->schedule->buildWeek($monday, $viewer);
        $plans = array_map(
            fn (TrainingPlan $p) => $this->serializer->trainingPlan($p),
            $this->plans->findForWeek($monday, $viewer),
        );

        return new JsonResponse([
            'week' => $monday->format('Y-m-d'),
            'weekLabel' => $this->formatWeekLabel($monday),
            'isoWeek' => $monday->format('o-\WW'),
            'slots' => $slots,
            'plans' => $plans,
        ]);
    }

    /** Téléchargement authentifié d'une PJ depuis le mobile. */
    #[Route('/api/training-slots/attachments/{id}/file', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function downloadAttachment(int $id): BinaryFileResponse
    {
        $att = $this->attachments->find($id);
        if ($att === null) {
            throw $this->createNotFoundException();
        }
        $path = $this->attachmentService->absolutePath($att);
        if ($path === null || !is_file($path)) {
            throw $this->createNotFoundException();
        }
        $resp = new BinaryFileResponse($path);
        $resp->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_INLINE,
            $att->getOriginalName(),
        );
        $resp->headers->set('Content-Type', $att->getMimeType());
        return $resp;
    }

    private function formatWeekLabel(\DateTimeImmutable $monday): string
    {
        $end = $monday->modify('+6 days');
        $fmtStart = new \IntlDateFormatter('fr_FR', \IntlDateFormatter::LONG, \IntlDateFormatter::NONE, null, null, 'd MMMM');
        $fmtEnd = new \IntlDateFormatter('fr_FR', \IntlDateFormatter::LONG, \IntlDateFormatter::NONE, null, null, 'd MMMM y');
        return sprintf('Semaine du %s au %s', (string) $fmtStart->format($monday), (string) $fmtEnd->format($end));
    }
}
