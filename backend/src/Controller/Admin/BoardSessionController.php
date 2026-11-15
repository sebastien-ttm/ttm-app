<?php

namespace App\Controller\Admin;

use App\Entity\BoardAction;
use App\Entity\BoardAgendaItem;
use App\Entity\BoardItemComment;
use App\Entity\BoardMeeting;
use App\Entity\User;
use App\Enum\BoardItemType;
use App\Enum\BoardPole;
use App\Repository\BoardAgendaItemRepository;
use App\Repository\BoardMeetingRepository;
use App\Repository\UserRepository;
use App\Security\BoardSessionVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Comité directeur — page « En séance » (membres du CoDir et administrateurs) : l'ordre du jour d'une réunion,
 * classé par pôle, avec les commentaires / réponses des membres, la saisie des colonnes Qui répond ? /
 * Réponse-Décision, les actions confiées à un ou plusieurs porteurs (membres du CoDir ou autres adhérents),
 * l'ajout d'un point soulevé en séance, la clôture de la réunion, le compte rendu imprimable et l'export CSV.
 */
#[IsGranted(BoardSessionVoter::ATTRIBUTE)]
class BoardSessionController extends AbstractController
{
    use EnsureAdminContextTrait;

    private const CSRF = 'board_session';

    public function __construct(
        private readonly BoardMeetingRepository $meetings,
        private readonly BoardAgendaItemRepository $items,
        private readonly UserRepository $users,
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
            'codir' => $this->users->findCommitteeMembers(),
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
        // « Porteur action » (texte libre) n'est plus saisi que sur les anciens points : sans le champ, on le conserve.
        $item->answer(
            (string) $request->request->get('respondent', ''),
            (string) $request->request->get('answer', ''),
            $request->request->has('actionOwner') ? (string) $request->request->get('actionOwner', '') : $item->getActionOwner(),
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

    /** Nouvelle action sur un point, confiée à un ou plusieurs porteurs (membres du CoDir ou autres adhérents). */
    #[Route('/admin/codir/items/{id}/actions', name: 'admin_board_action_add', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function addAction(int $id, Request $request): RedirectResponse
    {
        $this->validateCsrf($request);
        $item = $this->items->find($id);
        if ($item === null) {
            throw $this->createNotFoundException();
        }
        $text = trim((string) $request->request->get('text', ''));
        if ($text === '') {
            $this->addFlash('danger', 'Décrivez l\'action à mener.');
            return $this->backTo($item->getMeeting(), 'item-'.$item->getId());
        }
        $action = new BoardAction($item, $text);
        $action->setAssignees($this->assigneesFromRequest($request));
        $item->addAction($action);
        $this->em->persist($action);
        $this->em->flush();
        $this->addFlash('success', 'Action ajoutée.');

        return $this->backTo($item->getMeeting(), 'item-'.$item->getId());
    }

    #[Route('/admin/codir/actions/{id}', name: 'admin_board_action_update', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function updateAction(int $id, Request $request): RedirectResponse
    {
        $this->validateCsrf($request);
        $action = $this->em->getRepository(BoardAction::class)->find($id);
        if ($action === null) {
            throw $this->createNotFoundException();
        }
        $item = $action->getItem();
        $text = trim((string) $request->request->get('text', ''));
        if ($text === '') {
            $this->addFlash('danger', 'Décrivez l\'action à mener.');
            return $this->backTo($item->getMeeting(), 'item-'.$item->getId());
        }
        $action->setText($text)->setAssignees($this->assigneesFromRequest($request));
        $this->em->flush();
        $this->addFlash('success', 'Action modifiée.');

        return $this->backTo($item->getMeeting(), 'item-'.$item->getId());
    }

    #[Route('/admin/codir/actions/{id}/delete', name: 'admin_board_action_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function deleteAction(int $id, Request $request): RedirectResponse
    {
        $this->validateCsrf($request);
        $action = $this->em->getRepository(BoardAction::class)->find($id);
        if ($action === null) {
            throw $this->createNotFoundException();
        }
        $item = $action->getItem();
        $item->removeAction($action);
        $this->em->remove($action);
        $this->em->flush();
        $this->addFlash('success', 'Action supprimée.');

        return $this->backTo($item->getMeeting(), 'item-'.$item->getId());
    }

    /** Modération : retire un commentaire / une réponse déposé depuis l'appli. */
    #[Route('/admin/codir/comments/{id}/delete', name: 'admin_board_comment_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function deleteComment(int $id, Request $request): RedirectResponse
    {
        $this->validateCsrf($request);
        $comment = $this->em->getRepository(BoardItemComment::class)->find($id);
        if ($comment === null) {
            throw $this->createNotFoundException();
        }
        $item = $comment->getItem();
        $item->removeComment($comment);
        $this->em->remove($comment);
        $this->em->flush();
        $this->addFlash('success', 'Commentaire supprimé.');

        return $this->backTo($item->getMeeting(), 'item-'.$item->getId());
    }

    /** Recherche d'adhérents (nom ou prénom) pour désigner un porteur d'action qui ne fait pas partie du CoDir. */
    #[Route('/admin/codir/adherents', name: 'admin_board_adherents', methods: ['GET'])]
    public function searchAdherents(Request $request): JsonResponse
    {
        $q = trim((string) $request->query->get('q', ''));
        if (mb_strlen($q) < 2) {
            return new JsonResponse(['data' => []]);
        }

        return new JsonResponse(['data' => array_map(
            static fn (User $u) => ['id' => $u->getId(), 'name' => $u->getFullName()],
            $this->users->searchActiveAdherents($q, null, 12),
        )]);
    }

    /**
     * Compte rendu de la réunion, généré à partir de tous les points : commentaires et réponses des membres,
     * réponse / décision de la séance, actions et porteurs, puis le tableau récapitulatif des actions.
     * Page autonome, prête à imprimer ou à enregistrer en PDF depuis le navigateur.
     */
    #[Route('/admin/codir/meetings/{id}/compte-rendu', name: 'admin_board_minutes', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function minutes(int $id): Response
    {
        $meeting = $this->meetings->find($id);
        if ($meeting === null) {
            throw $this->createNotFoundException();
        }
        $groups = [];
        $actionRows = [];
        $commentCount = 0;
        foreach ($meeting->getSortedItems() as $item) {
            $groups[$item->getPole()->label()][] = $item;
            $commentCount += $item->getComments()->count();
            foreach ($item->getActions() as $action) {
                $actionRows[] = ['pole' => $item->getPole()->label(), 'point' => $item->getText(), 'text' => $action->getText(), 'owners' => implode(', ', $action->getAssigneeNames())];
            }
            if ($item->getActions()->isEmpty() && $item->getActionOwner() !== null) {
                $actionRows[] = ['pole' => $item->getPole()->label(), 'point' => $item->getText(), 'text' => $item->getAnswer() ?? 'Action à mener', 'owners' => $item->getActionOwner()];
            }
        }

        return $this->render('admin/board_minutes.html.twig', [
            'meeting' => $meeting,
            'groups' => $groups,
            'actionRows' => $actionRows,
            'commentCount' => $commentCount,
        ]);
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

    /**
     * Export au format du classeur : Pôle ; Qui s'exprime ? ; Information/Sujet/Question ; Qui répond ? ;
     * Réponse/Décision ; Porteur action — avec en plus les commentaires / réponses des membres et les actions.
     */
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
            fputcsv($out, ['Sujets Pôle', 'Qui s\'exprime ?', 'Type', 'Information/Sujet/Question', 'Commentaires / réponses des membres', 'Qui répond ?', 'Réponse/Décision', 'Porteur action'], ';');
            foreach ($meeting->getSortedItems() as $i) {
                $comments = array_map(
                    static fn (BoardItemComment $c) => $c->getAuthorName().' : '.$c->getText(),
                    $i->getComments()->toArray(),
                );
                $actions = array_map(
                    static fn (BoardAction $a) => $a->getText().($a->getAssigneeNames() !== [] ? ' ('.implode(', ', $a->getAssigneeNames()).')' : ''),
                    $i->getActions()->toArray(),
                );
                if ($i->getActionOwner() !== null) {
                    $actions[] = $i->getActionOwner();
                }
                fputcsv($out, [
                    $i->getPole()->label(),
                    $i->getSpeaker() ?? '',
                    $i->getType()->label(),
                    $i->getText(),
                    implode("\n", $comments),
                    $i->getRespondent() ?? '',
                    $i->getAnswer() ?? '',
                    implode("\n", $actions),
                ], ';');
            }
            fclose($out);
        });
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="CODIR_'.$meeting->getScheduledAt()->format('Y_m_d').'.csv"');

        return $response;
    }

    /**
     * Porteurs cochés (membres du CoDir) ou ajoutés par recherche (autres adhérents) : champ `assignees[]` = ids.
     *
     * @return list<User>
     */
    private function assigneesFromRequest(Request $request): array
    {
        $ids = [];
        foreach ($request->request->all('assignees') as $raw) {
            $id = (int) $raw;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        if ($ids === []) {
            return [];
        }

        return $this->users->findBy(['id' => array_slice(array_values($ids), 0, 30)]);
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
