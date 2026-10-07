<?php

namespace App\Controller\Admin;

use App\Entity\StaffPresenceTemplate;
use App\Entity\TrainingSlotTemplate;
use App\Enum\Profile;
use App\Repository\StaffPresenceRepository;
use App\Repository\StaffPresenceTemplateRepository;
use App\Repository\TrainingSlotTemplateRepository;
use App\Repository\UserRepository;
use App\Security\StaffScheduleSupervisionVoter;
use App\Service\Training\WeeklyScheduleService;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Semaines types des entraîneurs, modifiables par l'entraîneur principal
 * (case « Gestion entraîneurs ») ou un admin : tableau créneaux de la
 * semaine type × entraîneurs, une case par couple, cochée = présent en
 * temps normal. Mêmes données que l'écran mobile « Ma semaine type »
 * (StaffPresenceTemplate), modifiées ici pour le compte d'un autre.
 */
#[IsGranted(StaffScheduleSupervisionVoter::ATTRIBUTE)]
class StaffTemplateAdminController extends AbstractController
{
    use EnsureAdminContextTrait;

    private const CSRF_INTENT = 'staff_template_admin';

    public function __construct(
        private readonly StaffPresenceRepository $presences,
        private readonly StaffPresenceTemplateRepository $presenceTemplates,
        private readonly TrainingSlotTemplateRepository $templates,
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/admin/staff/semaines-types', name: 'admin_staff_templates', methods: ['GET'])]
    public function index(Request $request): Response
    {
        if ($r = $this->ensureAdminContext($request, 'admin_staff_templates')) {
            return $r;
        }

        $trainers = $this->presences->findActiveStaffByProfile(Profile::Entraineur);
        $checked = [];
        foreach ($trainers as $t) {
            $checked[$t->getId()] = $this->presenceTemplates->findPresentTemplateIds($t);
        }

        // Créneaux de la semaine type applicables cette semaine (même filtre
        // que l'écran mobile : évite les doublons des saisons clonées).
        $thisWeek = WeeklyScheduleService::snapToMonday(new \DateTimeImmutable('today'));
        $slots = array_values(array_filter(
            $this->templates->findActiveOrdered(),
            fn (TrainingSlotTemplate $tpl) => $tpl->appliesOn($thisWeek),
        ));
        usort($slots, fn ($a, $b) => [$a->getDayOfWeek(), $a->getStartTime()->format('H:i')]
            <=> [$b->getDayOfWeek(), $b->getStartTime()->format('H:i')]);

        $days = ['', 'Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi', 'Dimanche'];
        $rows = array_map(function (TrainingSlotTemplate $tpl) use ($trainers, $checked, $days) {
            $count = 0;
            foreach ($trainers as $t) {
                if (isset($checked[$t->getId()][$tpl->getId()])) {
                    $count++;
                }
            }
            return [
                'slot' => $tpl,
                'day' => $days[$tpl->getDayOfWeek()] ?? '?',
                'end' => $tpl->getStartTime()->modify('+'.$tpl->getDurationMinutes().' minutes'),
                'count' => $count,
            ];
        }, $slots);

        return $this->render('admin/staff_templates.html.twig', [
            'trainers' => $trainers,
            'rows' => $rows,
            'checked' => $checked,
        ]);
    }

    /** Coche / décoche un créneau de la semaine type d'un entraîneur. */
    #[Route('/admin/staff/semaines-types/toggle', name: 'admin_staff_templates_toggle', methods: ['POST'])]
    public function toggle(Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid(self::CSRF_INTENT, (string) $request->request->get('_token', ''))) {
            throw $this->createAccessDeniedException('CSRF invalide.');
        }
        $user = $this->users->find((int) $request->request->get('userId'));
        $template = $this->templates->find((int) $request->request->get('slotTemplateId'));
        if ($user === null || $template === null || !$user->hasProfile(Profile::Entraineur)) {
            throw $this->createNotFoundException();
        }
        $present = $request->request->get('present') === '1';

        $existing = $this->presenceTemplates->findOneByUserAndSlotTemplate($user, $template);
        if ($present && $existing === null) {
            $this->em->persist(new StaffPresenceTemplate($user, $template));
            try {
                $this->em->flush();
            } catch (UniqueConstraintViolationException) {
                // Double clic : la première requête a déjà posé l'état voulu.
            }
        } elseif (!$present && $existing !== null) {
            $this->em->remove($existing);
            $this->em->flush();
        }

        return new JsonResponse(['present' => $present]);
    }
}
