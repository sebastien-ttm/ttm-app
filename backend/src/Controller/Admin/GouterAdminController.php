<?php

namespace App\Controller\Admin;

use App\Entity\GouterSignup;
use App\Entity\User;
use App\Enum\Profile;
use App\Repository\GouterSignupRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Provider\AdminContextProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Gestion admin des positionnements « goûter du mercredi ».
 * Vue chronologique + ajout manuel + suppression, sur le modèle des
 * autres pages de supervision (encadrants).
 */
#[IsGranted('ROLE_EDITEUR')]
class GouterAdminController extends AbstractController
{
    private const DEFAULT_WEEKS = 16;

    public function __construct(
        private readonly GouterSignupRepository $signups,
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $em,
        private readonly AdminContextProvider $adminContextProvider,
    ) {
    }

    /**
     * Redirige vers l'URL "dashboard-forwardée" (`/admin?routeName=…`) quand
     * l'accès direct au path Symfony `/admin/gouters` bypasse le subscriber
     * EasyAdmin qui pose le contexte `ea` (indispensable au layout admin).
     * Sans ce garde-fou, une nav directe (bookmark, URL tapée à la main)
     * provoque une 500 « ea is null ».
     *
     * On construit l'URL manuellement (via admin_dashboard + query params)
     * plutôt que via AdminUrlGenerator pour éviter une boucle éventuelle
     * si la route se retrouvait cachée comme "pretty URL".
     */
    private function ensureAdminContext(Request $request, string $routeName): ?RedirectResponse
    {
        if ($this->adminContextProvider->getContext() !== null) {
            return null;
        }
        $params = ['routeName' => $routeName];
        $queryParams = $request->query->all();
        if ($queryParams !== []) {
            $params['routeParams'] = $queryParams;
        }
        $url = $this->generateUrl('admin_dashboard', $params);
        return $this->redirect($url);
    }

    #[Route('/admin/gouters', name: 'admin_gouters', methods: ['GET'])]
    public function index(Request $request): Response
    {
        if ($r = $this->ensureAdminContext($request, 'admin_gouters')) {
            return $r;
        }
        $today = new \DateTimeImmutable('today');
        $from = $this->parseDate((string) $request->query->get('from', '')) ?? $today;
        $to = $this->parseDate((string) $request->query->get('to', '')) ?? $from->modify('+'.self::DEFAULT_WEEKS.' weeks');

        $wednesdays = $this->wednesdaysInRange($from, $to);
        $existing = $wednesdays === []
            ? []
            : $this->signups->findInRange($wednesdays[0], end($wednesdays));

        $byDate = [];
        foreach ($existing as $s) {
            $byDate[$s->getDate()->format('Y-m-d')][] = $s;
        }

        $fmt = new \IntlDateFormatter(
            'fr_FR',
            \IntlDateFormatter::LONG,
            \IntlDateFormatter::NONE,
            null,
            null,
            'EEEE d MMMM y',
        );

        $rows = [];
        foreach ($wednesdays as $w) {
            $key = $w->format('Y-m-d');
            $rows[] = [
                'date' => $w,
                'dateLabel' => (string) $fmt->format($w),
                'signups' => $byDate[$key] ?? [],
            ];
        }

        // Liste des candidats à l'ajout manuel : profils Parent OU Jeune, actifs.
        $eligible = $this->users->createQueryBuilder('u')
            ->where('u.isActive = true')
            ->andWhere('JSON_CONTAINS(u.profiles, :p1) = 1 OR JSON_CONTAINS(u.profiles, :p2) = 1')
            ->setParameter('p1', json_encode(Profile::Parent->value))
            ->setParameter('p2', json_encode(Profile::Jeune->value))
            ->orderBy('u.nom', 'ASC')->addOrderBy('u.prenom', 'ASC')
            ->getQuery()->getResult();

        return $this->render('admin/gouters.html.twig', [
            'rows' => $rows,
            'from' => $from,
            'to' => $to,
            'eligible' => $eligible,
            'capacity' => GouterSignup::CAPACITY_PER_SLOT,
        ]);
    }

    #[Route('/admin/gouters/add', name: 'admin_gouters_add', methods: ['POST'])]
    public function add(Request $request): RedirectResponse
    {
        $this->validateCsrf($request, 'gouter_admin');

        $date = $this->parseDate((string) $request->request->get('date', ''));
        $userId = (int) $request->request->get('userId', 0);

        if ($date === null || (int) $date->format('N') !== 3) {
            $this->addFlash('error', 'Date invalide (mercredi requis).');
            return $this->redirectBack($request);
        }
        $user = $userId > 0 ? $this->users->find($userId) : null;
        if ($user === null) {
            $this->addFlash('error', 'Adhérent introuvable.');
            return $this->redirectBack($request);
        }
        if (!$user->hasProfile(Profile::Parent) && !$user->hasProfile(Profile::Jeune)) {
            $this->addFlash('error', 'Seuls les profils Parent ou Jeune peuvent être positionnés.');
            return $this->redirectBack($request);
        }

        if ($this->signups->findOneByDateUser($date, $user) !== null) {
            $this->addFlash('warning', sprintf('%s est déjà positionné(e) sur ce mercredi.', $user->getFullName()));
            return $this->redirectBack($request);
        }

        /** @var User $admin */
        $admin = $this->getUser();
        $signup = new GouterSignup($date, $user, createdBy: $admin);
        $this->em->persist($signup);
        $this->em->flush();
        $this->addFlash('success', sprintf('%s positionné(e) sur le %s.', $user->getFullName(), $date->format('d/m/Y')));

        return $this->redirectBack($request);
    }

    #[Route('/admin/gouters/{id}/delete', name: 'admin_gouters_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(int $id, Request $request): RedirectResponse
    {
        $this->validateCsrf($request, 'gouter_admin');

        $signup = $this->signups->find($id);
        if ($signup === null) {
            throw $this->createNotFoundException();
        }
        $name = $signup->getUser()->getFullName();
        $date = $signup->getDate()->format('d/m/Y');
        $this->em->remove($signup);
        $this->em->flush();
        $this->addFlash('success', sprintf('%s retiré(e) du %s.', $name, $date));

        return $this->redirectBack($request);
    }

    private function parseDate(string $raw): ?\DateTimeImmutable
    {
        if ($raw === '') {
            return null;
        }
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
        return $d !== false ? $d : null;
    }

    /** @return list<\DateTimeImmutable> */
    private function wednesdaysInRange(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $from = $from->setTime(0, 0, 0);
        $to = $to->setTime(0, 0, 0);
        $offset = (3 - (int) $from->format('N') + 7) % 7;
        $cursor = $from->modify("+$offset days");
        $result = [];
        while ($cursor <= $to) {
            $result[] = $cursor;
            $cursor = $cursor->modify('+7 days');
        }
        return $result;
    }

    private function validateCsrf(Request $request, string $intent): void
    {
        $token = (string) $request->request->get('_token', '');
        if (!$this->isCsrfTokenValid($intent, $token)) {
            throw $this->createAccessDeniedException('CSRF invalide.');
        }
    }

    private function redirectBack(Request $request): RedirectResponse
    {
        $from = $request->request->get('return_from') ?? $request->query->get('from');
        $to = $request->request->get('return_to') ?? $request->query->get('to');
        $params = [];
        if ($from) { $params['from'] = $from; }
        if ($to) { $params['to'] = $to; }
        return $this->redirectToRoute('admin_gouters', $params);
    }
}
