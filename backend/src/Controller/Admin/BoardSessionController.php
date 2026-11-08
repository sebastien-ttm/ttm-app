<?php

namespace App\Controller\Admin;

use App\Entity\BoardAgendaItem;
use App\Entity\BoardMeeting;
use App\Entity\User;
use App\Enum\BoardItemType;
use App\Enum\BoardPole;
use App\Repository\BoardAgendaItemRepository;
use App\Repository\BoardMeetingRepository;
use App\Security\BoardSessionVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Comité directeur — page « En séance » (membres du CoDir et administrateurs) : l'ordre du jour d'une réunion,
 * classé par pôle, avec la saisie des colonnes Qui répond ? / Réponse-Décision / Porteur action, l'ajout d'un point
 * soulevé en séance, la clôture de la réunion et l'export CSV du compte rendu.
 */
#[IsGranted(BoardSessionVoter::ATTRIBUTE)]
class BoardSessionController extends AbstractController
{
    use EnsureAdminContextTrait;

    private const CSRF = 'board_session';

    public function __construct(
        private readonly BoardMeetingRepository $meetings,
        private readonly BoardAgendaItemRepository $items,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/admin/codir/seance', name: 'admin_board_session', methods: ['GET'])]
    public function index(Request $request): Response
    {
        if ($r = $this->ensureAdminContext($request, 'admin_board_session')) {
            return $r;
        }
        $all = $this->meetings->findAllForListing();
        $id = (int) ($this->adminParam($request, 'meeting') ?? 0);
        $meeting = $id > 0 ? $this->meetings->find($id) : ($this->meetings->findNext() ?? ($all[0] ?? null));

        $groups = [];
        if ($meeting !== null) {
            foreach ($meeting->getSortedItems() as $item) {
                $groups[$item->getPole()->label()][] = $item;
            }
        }

        return $this->render('admin/board_session.html.twig', [
            'meeting' => $meeting,
            'meetings' => $all,
            'groups' => $groups,
            'poles' => BoardPole::cases(),
            'types' => BoardItemType::cases(),
            'csrf' => self::CSRF,
        ]);
    }

    #[Route('/admin/codir/items/{id}/answer', name: 'admin_board_item_answer', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function answer(int $id, Request $request): RedirectResponse
    {
        $this->validateCsrf($request);
        $item = $this->items->find($id);
        if ($item === null) {
            throw $this->createNotFoundException();
        }
        $item->answer(
            (string) $request->request->get('respondent', ''),
            (string) $request->request->get('answer', ''),
            (string) $request->request->get('actionOwner', ''),
        );
        $this->em->flush();
        $this->addFlash('success', 'Réponse enregistrée.');

        return $this->backTo($item->getMeeting(), 'item-'.$item->getId());
    }

    /** Point soulevé en séance (saisi par le secrétaire de séance pour le compte de « Qui s'exprime ? »). */
    #[Route('/admin/codir/meetings/{id}/items', name: 'admin_board_item_add', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function addItem(int $id, Request $request): RedirectResponse
    {
        $this->validateCsrf($request);
        $meeting = $this->meetings->find($id);
        if ($meeting === null) {
            throw $this->createNotFoundException();
        }
        $pole = BoardPole::tryFrom((string) $request->request->get('pole', ''));
        $type = BoardItemType::tryFrom((string) $request->request->get('type', ''));
        $text = trim((string) $request->request->get('text', ''));
        if ($pole === null || $type === null || $text === '') {
            $this->addFlash('danger', 'Pôle, type et texte sont obligatoires.');
            return $this->backTo($meeting);
        }
        /** @var User $user */
        $user = $this->getUser();
        $item = new BoardAgendaItem($meeting, $pole, $type, $text, $user);
        $speaker = trim((string) $request->request->get('speaker', ''));
        if ($speaker !== '') {
            $item->setSpeaker($speaker);
        }
        $meeting->addItem($item);
        $this->em->persist($item);
        $this->em->flush();
        $this->addFlash('success', 'Point ajouté.');

        return $this->backTo($meeting, 'item-'.$item->getId());
    }

    #[Route('/admin/codir/items/{id}/delete', name: 'admin_board_item_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function deleteItem(int $id, Request $request): RedirectResponse
    {
        $this->validateCsrf($request);
        $item = $this->items->find($id);
        if ($item === null) {
            throw $this->createNotFoundException();
        }
        $meeting = $item->getMeeting();
        $meeting->removeItem($item);
        $this->em->remove($item);
        $this->em->flush();
        $this->addFlash('success', 'Point supprimé.');

        return $this->backTo($meeting);
    }

    #[Route('/admin/codir/meetings/{id}/close', name: 'admin_board_meeting_close', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function close(int $id, Request $request): RedirectResponse
    {
        $this->validateCsrf($request);
        $meeting = $this->meetings->find($id);
        if ($meeting === null) {
            throw $this->createNotFoundException();
        }
        $meeting->setClosed($request->request->get('reopen') === null);
        $this->em->flush();
        $this->addFlash('success', $meeting->isClosed() ? 'Réunion close : le compte rendu est consultable dans l\'appli.' : 'Réunion rouverte.');

        return $this->backTo($meeting);
    }

    /** Compte rendu au format du classeur : Pôle ; Qui s'exprime ? ; Information/Sujet/Question ; Qui répond ? ; Réponse/Décision ; Porteur action. */
    #[Route('/admin/codir/meetings/{id}.csv', name: 'admin_board_export', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function exportCsv(int $id): StreamedResponse
    {
        $meeting = $this->meetings->find($id);
        if ($meeting === null) {
            throw $this->createNotFoundException();
        }
        $response = new StreamedResponse(function () use ($meeting): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [$meeting->getLabel().($meeting->getLocation() ? ' · '.$meeting->getLocation() : '')], ';');
            fputcsv($out, ['Sujets Pôle', 'Qui s\'exprime ?', 'Type', 'Information/Sujet/Question', 'Qui répond ?', 'Réponse/Décision', 'Porteur action'], ';');
            foreach ($meeting->getSortedItems() as $i) {
                fputcsv($out, [
                    $i->getPole()->label(),
                    $i->getSpeaker() ?? '',
                    $i->getType()->label(),
                    $i->getText(),
                    $i->getRespondent() ?? '',
                    $i->getAnswer() ?? '',
                    $i->getActionOwner() ?? '',
                ], ';');
            }
            fclose($out);
        });
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="CODIR_'.$meeting->getScheduledAt()->format('Y_m_d').'.csv"');

        return $response;
    }

    private function validateCsrf(Request $request): void
    {
        if (!$this->isCsrfTokenValid(self::CSRF, (string) $request->request->get('_token', ''))) {
            throw $this->createAccessDeniedException('CSRF invalide.');
        }
    }

    private function backTo(BoardMeeting $meeting, ?string $anchor = null): RedirectResponse
    {
        $url = $this->generateUrl('admin_board_session', ['meeting' => $meeting->getId()]);
        return $this->redirect($url.($anchor !== null ? '#'.$anchor : ''));
    }
}
