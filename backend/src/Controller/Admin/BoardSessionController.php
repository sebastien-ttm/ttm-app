<?php

namespace App\Controller\Admin;

use App\Entity\BoardAction;
use App\Entity\BoardAgendaItem;
use App\Entity\BoardItemComment;
use App\Entity\BoardMeeting;
use App\Entity\User;
use App\Enum\BoardItemType;
use App\Enum\BoardPole;
use App\Repository\BoardActionRepository;
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
 * Réponse-Décision, les actions confiées à un ou plusieurs porteurs (membres du CoDir ou autres adhérents) avec
 * leur échéance et leur clôture, l'ajout d'un point soulevé en séance, la modification d'un point par n'importe quel
 * membre du CoDir, la clôture de la réunion, le compte rendu imprimable (avec les actions en cours) et l'export CSV.
 */
#[IsGranted(BoardSessionVoter::ATTRIBUTE)]
class BoardSessionController extends AbstractController
{
    use EnsureAdminContextTrait;

    private const CSRF = 'board_session';

    public function __construct(
        private readonly BoardMeetingRepository $meetings,
        private readonly BoardAgendaItemRepository $items,
        private readonly BoardActionRepository $actions,
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
        $otherOpenActions = [];
        if ($meeting !== null) {
            foreach ($meeting->getSortedItems() as $item) {
                $groups[$item->getPole()->label()][] = $item;
            }
            // Actions en cours décidées lors d'autres réunions : à passer en revue (et clore) en début de séance.
            $otherOpenActions = array_values(array_filter(
                $this->actions->findOpen(),
                static fn (BoardAction $a) => $a->getItem()->getMeeting()->getId() !== $meeting->getId(),
            ));
        }

        return $this->render('admin/board_session.html.twig', [
            'meeting' => $meeting,
            'meetings' => $all,
            'groups' => $groups,
            'otherOpenActions' => $otherOpenActions,
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
        $item->recordAnswer(
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

    /**
     * Modification d'un point (pôle, type, « Qui s'exprime ? », texte) par n'importe quel membre du CoDir ou
     * administrateur — pas seulement son auteur —, y compris traité ou dans une réunion close (corriger le compte rendu).
     */
    #[Route('/admin/codir/items/{id}/update', name: 'admin_board_item_update', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function updateItem(int $id, Request $request): RedirectResponse
    {
        $this->validateCsrf($request);
        $item = $this->items->find($id);
        if ($item === null) {
            throw $this->createNotFoundException();
        }
        $meeting = $item->getMeeting();
        $pole = BoardPole::tryFrom((string) $request->request->get('pole', ''));
        $type = BoardItemType::tryFrom((string) $request->request->get('type', ''));
        $text = trim((string) $request->request->get('text', ''));
        if ($pole === null || $type === null || $text === '') {
            $this->addFlash('danger', 'Pôle, type et texte sont obligatoires : le point n\'a pas été modifié.');
            return $this->backTo($meeting, 'item-'.$item->getId());
        }
        if (mb_strlen($text) > BoardAgendaItem::TEXT_MAX) {
            $this->addFlash('danger', sprintf('Texte trop long (%d caractères max) : le point n\'a pas été modifié.', BoardAgendaItem::TEXT_MAX));
            return $this->backTo($meeting, 'item-'.$item->getId());
        }
        $item->setPole($pole)->setType($type)->setText($text)->setSpeaker((string) $request->request->get('speaker', ''));
        $this->em->flush();
        $this->addFlash('success', 'Point modifié.');

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
        $due = $this->parseDueDate($request);
        if ($due === false) {
            $this->addFlash('danger', 'Échéance invalide : l\'action n\'a pas été ajoutée.');
            return $this->backTo($item->getMeeting(), 'item-'.$item->getId());
        }
        $action = new BoardAction($item, $text);
        $action->setAssignees($this->assigneesFromRequest($request))->setDueDate($due);
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
        $due = $this->parseDueDate($request);
        if ($due === false) {
            $this->addFlash('danger', 'Échéance invalide : l\'action n\'a pas été modifiée.');
            return $this->backTo($item->getMeeting(), 'item-'.$item->getId());
        }
        $action->setText($text)->setAssignees($this->assigneesFromRequest($request))->setDueDate($due);
        $this->em->flush();
        $this->addFlash('success', 'Action modifiée.');

        return $this->backTo($item->getMeeting(), 'item-'.$item->getId());
    }

    /**
     * Clôture une action (ou la rouvre avec `reopen`). Le champ `back` indique la réunion à réafficher : on clôt
     * aussi, depuis la séance en cours, les actions décidées lors de réunions précédentes.
     */
    #[Route('/admin/codir/actions/{id}/close', name: 'admin_board_action_close', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function closeAction(int $id, Request $request): RedirectResponse
    {
        $this->validateCsrf($request);
        $action = $this->em->getRepository(BoardAction::class)->find($id);
        if ($action === null) {
            throw $this->createNotFoundException();
        }
        $item = $action->getItem();
        $action->setClosed($request->request->get('reopen') === null);
        $this->em->flush();
        $this->addFlash('success', $action->isClosed() ? 'Action clôturée.' : 'Action rouverte.');

        $back = $this->meetings->find((int) $request->request->get('back', 0)) ?? $item->getMeeting();
        $sameMeeting = $back->getId() === $item->getMeeting()->getId();

        return $this->backTo($back, $sameMeeting ? 'item-'.$item->getId() : 'actions-en-cours');
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

    /** Modération : retire un commentaire / une réponse déposé depuis l'appli, avec les réponses qu'il a reçues. */
    #[Route('/admin/codir/comments/{id}/delete', name: 'admin_board_comment_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function deleteComment(int $id, Request $request): RedirectResponse
    {
        $this->validateCsrf($request);
        $comment = $this->em->getRepository(BoardItemComment::class)->find($id);
        if ($comment === null) {
            throw $this->createNotFoundException();
        }
        $item = $comment->getItem();
        // Le commentaire et ses réponses (la base les supprime en cascade ; la collection en mémoire doit suivre).
        foreach ($item->getCommentThread($comment) as $c) {
            $item->removeComment($c);
            $this->em->remove($c);
        }
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
        $commentCount = 0;
        $decidedCount = 0;
        foreach ($meeting->getSortedItems() as $item) {
            $groups[$item->getPole()->label()][] = $item;
            $commentCount += $item->getComments()->count();
            $decidedCount += $item->getActions()->count();
        }

        return $this->render('admin/board_minutes.html.twig', [
            'meeting' => $meeting,
            'groups' => $groups,
            'commentCount' => $commentCount,
            'decidedCount' => $decidedCount,
            // Suivi : toutes les actions en cours à la date de la réunion (nouvelles et reportées des précédentes).
            'openActions' => $this->actions->findOpenAsOf($meeting),
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
                // En fil : une réponse est préfixée de « ↳ » et du nom de l'auteur du commentaire auquel elle répond.
                $comments = array_map(
                    static fn (array $t) => ($t['replyTo'] !== null ? '↳ ' : '')
                        .$t['comment']->getAuthorName()
                        .($t['replyTo'] !== null ? ' (à '.$t['replyTo'].')' : '')
                        .' : '.$t['comment']->getText(),
                    $i->getThreadedComments(),
                );
                $actions = array_map(
                    static fn (BoardAction $a) => $a->getText()
                        .($a->getAssigneeNames() !== [] ? ' ('.implode(', ', $a->getAssigneeNames()).')' : '')
                        .($a->getDueDate() !== null ? ' — échéance '.$a->getDueDate()->format('d/m/Y') : '')
                        .($a->isClosed() ? ' — clôturée le '.$a->getClosedAt()->format('d/m/Y') : ''),
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
     * Échéance saisie (champ `dueDate`, AAAA-MM-JJ) : null si vide, false si invalide.
     */
    private function parseDueDate(Request $request): \DateTimeImmutable|false|null
    {
        $raw = trim((string) $request->request->get('dueDate', ''));
        if ($raw === '') {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
        $errors = \DateTimeImmutable::getLastErrors();
        if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            return false;
        }

        return $date;
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
