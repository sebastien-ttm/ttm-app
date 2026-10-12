<?php

namespace App\Controller\Api;

use App\Entity\User;
use App\Service\AvatarService;
use App\Service\Training\TrainingRegistrationService;
use App\Service\Training\WeeklyScheduleService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Inscriptions aux entraînements sur inscription (places limitées, une seule inscription par semaine et par
 * adhérent). Un créneau se désigne par la semaine (`week`, n'importe quel jour) et par `templateId` (créneau de la
 * semaine type) ou `slotId` (créneau occasionnel) — les identifiants des lignes de GET /api/training-schedule.
 */
#[IsGranted('ROLE_USER')]
class TrainingRegistrationController extends AbstractController
{
    public function __construct(
        private readonly TrainingRegistrationService $service,
        private readonly AvatarService $avatars,
    ) {
    }

    /**
     * État des inscriptions du créneau (places, inscrit ou non, inscription sur un autre créneau de la semaine…)
     * et liste des inscrits, dans l'ordre d'inscription.
     *
     * GET /api/training-registrations?week=YYYY-MM-DD&templateId=12  (ou &slotId=5)
     */
    #[Route('/api/training-registrations', methods: ['GET'])]
    public function show(Request $request): JsonResponse
    {
        $target = $this->parseTarget($request->query->all());
        if ($target instanceof JsonResponse) {
            return $target;
        }
        [$week, $templateId, $slotId] = $target;

        /** @var User $viewer */
        $viewer = $this->getUser();
        $state = $this->service->stateFor($viewer, $week, $templateId, $slotId);
        if ($state === null) {
            return new JsonResponse(['error' => 'Créneau introuvable.'], Response::HTTP_NOT_FOUND);
        }

        $participants = [];
        if ($state['registration'] !== null) {
            foreach ($this->service->participants($state['row'], WeeklyScheduleService::snapToMonday($week)) as $user) {
                $participants[] = [
                    'id' => $user->getId(),
                    'prenom' => $user->getPrenom(),
                    'nom' => $user->getNom(),
                    'avatarUrl' => $this->avatars->urlFor($user),
                ];
            }
        }

        return new JsonResponse(['registration' => $state['registration'], 'participants' => $participants]);
    }

    /**
     * Inscrit ou désinscrit le viewer.
     *
     * POST /api/training-registrations   { week, templateId? | slotId?, registered: true|false }
     *
     * Refus (409/422/404) : { error, code, registration } avec code = not_found | not_required | closed | full |
     * already_registered_this_week ; `registration` = état à jour du créneau.
     */
    #[Route('/api/training-registrations', methods: ['POST'])]
    public function set(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent() ?: '{}', true);
        $target = $this->parseTarget(is_array($payload) ? $payload : []);
        if ($target instanceof JsonResponse) {
            return $target;
        }
        [$week, $templateId, $slotId] = $target;
        $registered = is_array($payload) && ($payload['registered'] ?? true) !== false;

        /** @var User $viewer */
        $viewer = $this->getUser();
        $refusal = $this->service->setRegistered($viewer, $week, $templateId, $slotId, $registered);
        try {
            $state = $this->service->stateFor($viewer, $week, $templateId, $slotId);
        } catch (\Throwable) {
            $state = null; // ex. EntityManager fermé après un conflit d'unicité : le refus suffit au client
        }

        if ($refusal !== null) {
            return new JsonResponse([
                'error' => $refusal['message'],
                'code' => $refusal['code'],
                'registration' => $state['registration'] ?? null,
            ], $refusal['status']);
        }

        return new JsonResponse(['registration' => $state['registration'] ?? null]);
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{0: \DateTimeImmutable, 1: ?int, 2: ?int}|JsonResponse
     */
    private function parseTarget(array $input): array|JsonResponse
    {
        try {
            $week = new \DateTimeImmutable((string) ($input['week'] ?? ''));
        } catch (\Exception) {
            return new JsonResponse(['error' => 'Paramètre "week" invalide (format attendu : YYYY-MM-DD).'], Response::HTTP_BAD_REQUEST);
        }
        $templateId = isset($input['templateId']) && $input['templateId'] !== '' ? (int) $input['templateId'] : null;
        $slotId = isset($input['slotId']) && $input['slotId'] !== '' ? (int) $input['slotId'] : null;
        if ($templateId === null && $slotId === null) {
            return new JsonResponse(['error' => 'Indiquez templateId ou slotId.'], Response::HTTP_BAD_REQUEST);
        }

        // Comme le planning : les semaines passées ne sont pas accessibles aux adhérents.
        if (!$this->isGranted('ROLE_ADMIN')
            && WeeklyScheduleService::snapToMonday($week) < WeeklyScheduleService::snapToMonday(new \DateTimeImmutable('today'))) {
            return new JsonResponse(['error' => 'Les semaines passées ne sont pas accessibles.'], Response::HTTP_FORBIDDEN);
        }

        return [$week, $templateId, $slotId];
    }
}
