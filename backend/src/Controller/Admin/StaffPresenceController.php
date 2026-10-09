<?php

namespace App\Controller\Admin;

use App\Entity\StaffPresence;
use App\Enum\Profile;
use App\Repository\StaffDayUnavailabilityRepository;
use App\Repository\StaffPresenceRepository;
use App\Repository\StaffWeekUnavailabilityRepository;
use App\Repository\TrainingSlotRepository;
use App\Repository\TrainingSlotTemplateRepository;
use App\Repository\UserRepository;
use App\Security\StaffScheduleSupervisionVoter;
use App\Service\Training\StaffPresenceService;
use App\Service\Training\WeeklyScheduleService;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * « Présence entraînements » : une semaine sous forme de tableau, une
 * ligne par créneau et une colonne par entraîneur / encadrant (deux
 * sections). Chaque case est cochée = présent. Les valeurs viennent de ce
 * que chacun a saisi sur le mobile ; seuls l'entraîneur référent (case
 * « Entraîneur référent » de la fiche adhérent) et les admins peuvent
 * les surcharger — les autres entraîneurs voient la page en lecture seule.
 *
 * Il n'y a plus de notion de « réservé » côté backend : présent (statut
 * scheduled ou attended) ou non. Décocher pose un « non dispo » explicite
 * pour que le mobile de la personne reflète le choix du référent.
 */
#[IsGranted('ROLE_ENTRAINEUR')]
class StaffPresenceController extends AbstractController
{
    use EnsureAdminContextTrait;

    private const CSRF_INTENT = 'staff_presence_matrix';

    private const DAY_NAMES = [1 => 'Lundi', 2 => 'Mardi', 3 => 'Mercredi', 4 => 'Jeudi', 5 => 'Vendredi', 6 => 'Samedi', 7 => 'Dimanche'];

    public function __construct(
        private readonly StaffPresenceRepository $presences,
        private readonly TrainingSlotRepository $slots,
        private readonly TrainingSlotTemplateRepository $templates,
        private readonly UserRepository $users,
        private readonly StaffPresenceService $service,
        private readonly WeeklyScheduleService $schedule,
        private readonly EntityManagerInterface $em,
        private readonly StaffWeekUnavailabilityRepository $unavailabilities,
        private readonly StaffDayUnavailabilityRepository $dayUnavailabilities,
        private readonly AdminUrlGenerator $adminUrlGenerator,
    ) {
    }

    #[Route('/admin/staff/presences', name: 'admin_staff_presence_trainings', methods: ['GET'])]
    public function index(Request $request): Response
    {
        if ($r = $this->ensureAdminContext($request, 'admin_staff_presence_trainings')) {
            return $r;
        }

        // Derrière le forward EasyAdmin (`/admin?routeName=…&routeParams[week]=…`),
        // `week` n'est pas dans la query string de la requête mais dans les
        // attributs (routeParams y sont fusionnés) : on le cherche aux deux endroits.
        $rawWeek = $request->attributes->get('week')
            ?? $request->query->all('routeParams')['week']
            ?? $request->query->get('week');
        $week = $this->parseWeek(is_string($rawWeek) ? $rawWeek : null);

        // Deux sections de colonnes : entraîneurs puis encadrants.
        $sections = [];
        foreach ([[Profile::Entraineur, 'Entraîneurs'], [Profile::Encadrant, 'Encadrants']] as [$profile, $label]) {
            $staff = $this->presences->findActiveStaffByProfile($profile);
            if ($staff !== []) {
                $sections[] = ['key' => $profile->value, 'label' => $label, 'staff' => $staff];
            }
        }

        // present[slotId][userId] = true ; les tâches hors entraînement
        // (sans créneau) sont listées à part sous le tableau.
        $present = [];
        $customTasks = [];
        foreach ($this->presences->findStaffPresencesForWeekGroupedByUser($week) as $userId => $list) {
            foreach ($list as $p) {
                if ($p->getSlot() === null) {
                    $customTasks[] = $p;
                } elseif ($p->getStatus() !== StaffPresence::STATUS_UNAVAILABLE) {
                    $present[$p->getSlot()->getId()][$userId] = true;
                }
            }
        }

        // Absences déclarées (semaine / jour) : affichées en lecture seule
        // dans les cases décochées, avec leur motif.
        $weekAbsence = [];
        foreach ($this->unavailabilities->findForWeek($week) as $u) {
            $weekAbsence[$u->getUser()->getId()] = $u;
        }
        $dayAbsence = [];
        foreach ($this->dayUnavailabilities->findForWeek($week) as $d) {
            $dayAbsence[$d->getUser()->getId()][$d->getDate()->format('Y-m-d')] = $d;
        }

        $days = [];
        foreach ($this->schedule->buildWeek($week) as $row) {
            $slotId = $row['id'];
            $templateId = $row['templateId'];
            $cancelled = !empty($row['isCancelled']);

            $counts = [Profile::Entraineur->value => 0, Profile::Encadrant->value => 0];
            $cells = [];
            foreach ($sections as $section) {
                foreach ($section['staff'] as $member) {
                    $uid = $member->getId();
                    $isPresent = !$cancelled && $slotId !== null && isset($present[$slotId][$uid]);
                    if ($isPresent) {
                        $counts[$section['key']]++;
                    }
                    $absence = $isPresent ? null : ($dayAbsence[$uid][$row['date']] ?? $weekAbsence[$uid] ?? null);
                    $cells[$uid] = ['present' => $isPresent, 'absent' => $absence !== null, 'reason' => $absence?->getReason()];
                }
            }

            $dow = (int) $row['dayOfWeek'];
            $days[$dow] ??= [
                'label' => (self::DAY_NAMES[$dow] ?? '?').' '.$week->modify(sprintf('+%d days', $dow - 1))->format('d/m'),
                'rows' => [],
            ];
            $days[$dow]['rows'][] = [
                // Un créneau virtuel (pas encore de TrainingSlot) se désigne par son
                // template ; la première coche le matérialise (setForTemplate).
                'choice' => $slotId !== null ? 's:'.$slotId : 't:'.$templateId,
                'startTime' => $row['startTime'],
                'endTime' => (new \DateTimeImmutable($row['date'].' '.$row['startTime']))
                    ->modify(sprintf('+%d minutes', (int) $row['durationMinutes']))->format('H:i'),
                'title' => $row['title'],
                'location' => $row['location'],
                'sportIcon' => $row['sportIcon'],
                'isCancelled' => $cancelled,
                'isOccasional' => !empty($row['isOccasional']),
                'cells' => $cells,
                'counts' => $counts,
            ];
        }
        ksort($days);

        $prev = $week->modify('-7 days')->format('Y-m-d');
        $next = $week->modify('+7 days')->format('Y-m-d');
        $today = WeeklyScheduleService::snapToMonday(new \DateTimeImmutable('today'))->format('Y-m-d');

        return $this->render('admin/staff_presence_trainings.html.twig', [
            'week' => $week,
            'weekHuman' => $this->humanWeekLabel($week),
            'prevUrl' => $this->adminRoute('admin_staff_presence_trainings', ['week' => $prev]),
            'nextUrl' => $this->adminRoute('admin_staff_presence_trainings', ['week' => $next]),
            'todayUrl' => $this->adminRoute('admin_staff_presence_trainings', ['week' => $today]),
            'sections' => $sections,
            'staffCount' => array_sum(array_map(fn (array $s) => count($s['staff']), $sections)),
            'days' => $days,
            'weekAbsence' => $weekAbsence,
            'customTasks' => $customTasks,
            'canEdit' => $this->isGranted(StaffScheduleSupervisionVoter::ATTRIBUTE),
        ]);
    }

    /**
     * Coche / décoche la présence d'un membre du staff sur un créneau.
     * Réservé à l'entraîneur référent et aux admins.
     *
     * POST : _token, week, userId, choice ("s:<slotId>" ou "t:<templateId>"),
     * present ("1"/"0"). Cocher = scheduled (et retire un éventuel « non
     * dispo cette semaine », comme côté mobile) ; décocher = unavailable.
     */
    #[IsGranted(StaffScheduleSupervisionVoter::ATTRIBUTE)]
    #[Route('/admin/staff/presences/toggle', name: 'admin_staff_presence_toggle', methods: ['POST'])]
    public function toggle(Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid(self::CSRF_INTENT, (string) $request->request->get('_token', ''))) {
            throw $this->createAccessDeniedException('CSRF invalide.');
        }
        if (!preg_match('/^([st]):(\d+)$/', (string) $request->request->get('choice', ''), $m)) {
            throw $this->createNotFoundException();
        }
        $user = $this->users->find((int) $request->request->get('userId'));
        if ($user === null || !$user->isActive() || (!$user->isEntraineur() && !$user->isEncadrant())) {
            throw $this->createNotFoundException();
        }

        $present = $request->request->get('present') === '1';
        $status = $present ? StaffPresence::STATUS_SCHEDULED : StaffPresence::STATUS_UNAVAILABLE;

        if ($m[1] === 's') {
            $slot = $this->slots->find((int) $m[2]);
            if ($slot === null) {
                throw $this->createNotFoundException();
            }
            if ($slot->isCancelled()) {
                return new JsonResponse(['error' => 'Créneau annulé.'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $this->service->setForSlot($user, $slot, $status);
            $monday = $slot->getWeekStartsAt();
        } else {
            $template = $this->templates->find((int) $m[2]);
            if ($template === null) {
                throw $this->createNotFoundException();
            }
            $monday = $this->parseWeek($request->request->get('week'));
            $this->service->setForTemplate($user, $template, $monday, $status);
        }

        if ($present) {
            $weekAbsence = $this->unavailabilities->findOneByUserAndWeek($user, $monday);
            if ($weekAbsence !== null) {
                $this->em->remove($weekAbsence);
            }
        }
        $this->em->flush();

        return new JsonResponse(['present' => $present]);
    }

    /** URL vers une route admin custom en gardant le contexte EasyAdmin (menu, layout). */
    private function adminRoute(string $routeName, array $params = []): string
    {
        return $this->adminUrlGenerator
            ->unsetAll()
            ->setRoute($routeName, $params)
            ->generateUrl();
    }

    private function parseWeek(?string $raw): \DateTimeImmutable
    {
        try {
            $d = $raw && $raw !== '' ? new \DateTimeImmutable($raw) : new \DateTimeImmutable('today');
        } catch (\Exception) {
            $d = new \DateTimeImmutable('today');
        }
        return WeeklyScheduleService::snapToMonday($d);
    }

    private function humanWeekLabel(\DateTimeImmutable $monday): string
    {
        $end = $monday->modify('+6 days');
        $fmtStart = new \IntlDateFormatter('fr_FR', \IntlDateFormatter::LONG, \IntlDateFormatter::NONE, null, null, 'EEEE d MMMM');
        $fmtEnd = new \IntlDateFormatter('fr_FR', \IntlDateFormatter::LONG, \IntlDateFormatter::NONE, null, null, 'EEEE d MMMM y');
        return sprintf('Semaine du %s au %s', (string) $fmtStart->format($monday), (string) $fmtEnd->format($end));
    }
}
