<?php

namespace App\Controller\Admin;

use App\Entity\Mailing;
use App\Entity\MailingRecipient;
use App\Entity\User;
use App\Enum\Profile;
use App\Repository\MailingRecipientRepository;
use App\Repository\MailingRepository;
use App\Service\Mailing\MailingMailFactory;
use App\Service\Mailing\MailingService;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Écran de suivi d'un mailing : vérification des destinataires, aperçu, envoi de test,
 * lancement, puis avancement de l'envoi (envoyés, en attente, échecs) avec pause,
 * reprise, annulation et relance des échecs. Réservé aux administrateurs.
 */
#[IsGranted('ROLE_ADMIN')]
class MailingAdminController extends AbstractController
{
    use EnsureAdminContextTrait;

    private const CSRF_INTENT = 'mailing';
    /** Destinataires affichés au maximum dans le tableau de suivi. */
    private const RECIPIENT_LIMIT = 300;
    /** Aperçu de la liste des destinataires avant lancement. */
    private const SAMPLE_LIMIT = 40;

    public function __construct(
        private readonly MailingRepository $mailings,
        private readonly MailingRecipientRepository $recipients,
        private readonly MailingService $service,
        private readonly MailingMailFactory $mails,
        private readonly AdminUrlGenerator $adminUrlGenerator,
        #[Autowire('%app.mailing.daily_limit%')]
        private readonly int $dailyLimit,
    ) {
    }

    #[Route('/admin/mailings/{id}', name: 'admin_mailing_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id, Request $request): Response
    {
        if ($r = $this->ensureAdminContext($request, 'admin_mailing_show')) {
            return $r;
        }
        $mailing = $this->findMailing($id);
        /** @var User $viewer */
        $viewer = $this->getUser();

        $param = $this->adminParam($request, 'status');
        $filter = $param !== null && array_key_exists($param, MailingRecipient::statusLabels()) ? $param : null;

        $audience = $mailing->isDraft() ? $this->service->audience($mailing) : null;
        $counts = $this->recipients->countsByStatus($mailing);

        $profileLabels = [];
        foreach ($mailing->getAudience() as $value) {
            $profileLabels[] = Profile::tryFrom($value)?->label() ?? $value;
        }

        return $this->render('admin/mailing_show.html.twig', [
            'mailing' => $mailing,
            'audience' => $audience,
            'sample' => $audience !== null ? array_slice($audience['eligible'], 0, self::SAMPLE_LIMIT) : [],
            'profileLabels' => $profileLabels,
            'counts' => $counts,
            'total' => array_sum($counts),
            'recipients' => $mailing->isDraft() ? [] : $this->recipients->findForAdmin($mailing, $filter, self::RECIPIENT_LIMIT),
            'recipientLimit' => self::RECIPIENT_LIMIT,
            'filter' => $filter,
            'statusLabels' => MailingRecipient::statusLabels(),
            'lastSentAt' => $mailing->isDraft() ? null : $this->recipients->lastSentAt($mailing),
            'dailyLimit' => $this->dailyLimit,
            'sentLast24h' => $this->recipients->countSentSince(new \DateTimeImmutable('-24 hours')),
            // Aperçu : le courriel tel qu'il est rendu, avec le nom de l'administrateur.
            'preview' => $this->renderView('email/mailing.html.twig', $this->mails->context($mailing, $viewer->getPrenom(), $viewer->getNom(), null)),
            'indexUrl' => $this->adminUrlGenerator->unsetAll()->setController(MailingCrudController::class)->generateUrl(),
            'editUrl' => $this->adminUrlGenerator->unsetAll()
                ->setController(MailingCrudController::class)
                ->setAction(Action::EDIT)
                ->setEntityId($mailing->getId())
                ->generateUrl(),
        ]);
    }

    /** Envoie le mailing à l'administrateur connecté, immédiatement. */
    #[Route('/admin/mailings/{id}/test', name: 'admin_mailing_test', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function test(int $id, Request $request): RedirectResponse
    {
        $this->assertCsrf($request);
        $mailing = $this->findMailing($id);
        /** @var User $viewer */
        $viewer = $this->getUser();

        try {
            $this->service->sendTest($mailing, $viewer);
            $this->addFlash('success', sprintf('E-mail de test envoyé à %s (pensez à regarder aussi les indésirables).', $viewer->getEmail()));
        } catch (\Throwable $e) {
            $this->addFlash('danger', 'Le test n\'a pas pu être envoyé : '.$e->getMessage());
        }

        return $this->backToShow($id);
    }

    /** Fige les destinataires et lance l'envoi. `expected` = nombre de destinataires affiché à l'écran. */
    #[Route('/admin/mailings/{id}/send', name: 'admin_mailing_send', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function send(int $id, Request $request): RedirectResponse
    {
        $this->assertCsrf($request);
        $mailing = $this->findMailing($id);

        // Garde-fou : la liste a pu changer (import, désinscription) depuis l'affichage de l'écran.
        $expected = (int) $request->request->get('expected', 0);
        $actual = count($this->service->audience($mailing)['eligible']);
        if ($mailing->isDraft() && $expected !== $actual) {
            $this->addFlash('danger', sprintf(
                'Le nombre de destinataires a changé (%d affichés, %d maintenant) : vérifiez la liste puis relancez l\'envoi.',
                $expected,
                $actual,
            ));

            return $this->backToShow($id);
        }

        try {
            $count = $this->service->start($mailing);
            $this->addFlash('success', sprintf('Envoi lancé pour %d destinataires : il part par petits lots espacés, vous pouvez suivre l\'avancement ici.', $count));
        } catch (\DomainException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->backToShow($id);
    }

    /** Pause, reprise, relance du traitement, annulation, relance des échecs. */
    #[Route(
        '/admin/mailings/{id}/{action}',
        name: 'admin_mailing_action',
        methods: ['POST'],
        requirements: ['id' => '\d+', 'action' => 'pause|resume|nudge|cancel|retry'],
    )]
    public function action(int $id, string $action, Request $request): RedirectResponse
    {
        $this->assertCsrf($request);
        $mailing = $this->findMailing($id);

        try {
            switch ($action) {
                case 'pause':
                    $this->service->pause($mailing);
                    $this->addFlash('success', 'Envoi mis en pause.');
                    break;
                case 'resume':
                    $this->service->resume($mailing);
                    $this->addFlash('success', 'Envoi repris.');
                    break;
                case 'nudge':
                    $this->service->nudge($mailing);
                    $this->addFlash('success', 'Traitement relancé.');
                    break;
                case 'cancel':
                    $this->service->cancel($mailing);
                    $this->addFlash('success', 'Envoi annulé : les e-mails encore en attente ne partiront pas.');
                    break;
                case 'retry':
                    $count = $this->service->retryFailed($mailing);
                    $this->addFlash('success', sprintf('%d envoi(s) en échec remis en attente.', $count));
                    break;
            }
        } catch (\DomainException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->backToShow($id);
    }

    private function findMailing(int $id): Mailing
    {
        return $this->mailings->find($id) ?? throw $this->createNotFoundException('Mailing introuvable.');
    }

    private function assertCsrf(Request $request): void
    {
        if (!$this->isCsrfTokenValid(self::CSRF_INTENT, (string) $request->request->get('_token', ''))) {
            throw $this->createAccessDeniedException('CSRF invalide.');
        }
    }

    /** Retour sur l'écran de suivi, par le dashboard EasyAdmin (qui pose le contexte de la page). */
    private function backToShow(int $id): RedirectResponse
    {
        return $this->redirect($this->generateUrl('admin_dashboard', [
            'routeName' => 'admin_mailing_show',
            'routeParams' => ['id' => $id],
        ]));
    }
}
