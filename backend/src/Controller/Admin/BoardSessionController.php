<?php

namespace App\Controller\Admin;

use App\Entity\BoardAction;
use App\Entity\BoardAgendaItem;
use App\Entity\BoardItemComment;
use App\Entity\BoardMeeting;
use App\Entity\BoardMeetingAttendee;
use App\Entity\User;
use App\Message\CreateBoardMeetingCalendarEventMessage;
use App\Enum\BoardAttendance;
use App\Enum\BoardItemType;
use App\Enum\BoardPole;
use App\Repository\BoardActionRepository;
use App\Repository\BoardAgendaItemRepository;
use App\Repository\BoardMeetingRepository;
use App\Repository\UserRepository;
use App\Security\BoardSessionVoter;
use App\Service\Board\BoardMeetingNotifier;
use App\Service\Board\MinutesSignatureService;
use App\Service\Google\GoogleCalendarClient;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Comité directeur — page « En séance » (membres du CoDir et administrateurs) : l'ordre du jour d'une réunion,
 * classé par pôle, avec les commentaires / réponses des membres, la saisie des colonnes Qui répond ? /
 * Réponse-Décision, les actions confiées à un ou plusieurs porteurs (membres du CoDir ou autres adhérents) avec
 * leur échéance et leur clôture (toutes les actions, y compris celles des autres réunions, se clôturent et se
 * rouvrent d'ici), l'ajout d'un point soulevé en séance, la modification d'un point par n'importe quel membre du
 * CoDir, les présences (présents, excusés, invités), la clôture de la réunion, le compte rendu imprimable (avec les
 * présences et les actions en cours) et l'export CSV.
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
        private readonly GoogleCalendarClient $calendar,
        private readonly MessageBusInterface $bus,
        private readonly BoardMeetingNotifier $notifier,
        private readonly MinutesSignatureService $signatures,
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
        $otherClosedActions = [];
        if ($meeting !== null) {
            foreach ($meeting->getSortedItems() as $item) {
                $groups[$item->getPole()->label()][] = $item;
            }
            // Actions décidées lors d'autres réunions : en cours (à passer en revue et clore en début de séance) et déjà
            // clôturées (à rouvrir au besoin) — toutes les actions se clôturent et se rouvrent depuis ici.
            $notThisMeeting = static fn (BoardAction $a) => $a->getItem()->getMeeting()->getId() !== $meeting->getId();
            $otherOpenActions = array_values(array_filter($this->actions->findOpen(), $notThisMeeting));
            $otherClosedActions = array_values(array_filter($this->actions->findClosed(), $notThisMeeting));
        }

        $codir = $this->users->findCommitteeMembers();
        // « Qui s'exprime ? » : membres du CoDir, puis entraîneurs invités (sans doublon avec le CoDir).
        $coaches = array_values(array_filter(
            $this->users->findCoaches(),
            static fn (User $u) => $u->getBoardRole() === null,
        ));
        usort($coaches, static fn (User $a, User $b) => strcasecmp($a->getNom().' '.$a->getPrenom(), $b->getNom().' '.$b->getPrenom()));
        /** @var User $viewer */
        $viewer = $this->getUser();

        return $this->render('admin/board_session.html.twig', [
            'meeting' => $meeting,
            'meetings' => $all,
            'groups' => $groups,
            'otherOpenActions' => $otherOpenActions,
            'otherClosedActions' => $otherClosedActions,
            'attendanceGroups' => $meeting !== null ? $this->attendanceGroups($meeting, $codir, $coaches) : [],
            'attendanceStatus' => $meeting !== null ? $this->attendanceStatuses($meeting) : [],
            'poles' => BoardPole::cases(),
            'types' => BoardItemType::cases(),
            'codir' => $codir,
            'speakersCodir' => $codir,
            'speakersCoaches' => $coaches,
            'speakerNames' => array_map(static fn (User $u) => $u->getFullName(), [...$codir, ...$coaches]),
            // À l'ajout d'un point, l'intervenant proposé par défaut est l'utilisateur connecté s'il peut s'exprimer.
            'defaultSpeakerId' => $viewer->canSpeakAtBoard() ? $viewer->getId() : null,
            // « Qui répond ? » peut être le CoDir dans son ensemble.
            'collectiveRespondent' => BoardAgendaItem::RESPONDENT_CODIR,
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
        // « Qui répond ? » : « codir » = le CoDir collectivement ; sinon un membre du CoDir ou un entraîneur invité
        // (« keep » conserve une ancienne saisie libre ; vide = non renseigné).
        $rawRespondent = trim((string) $request->request->get('respondent', 'keep'));
        if ($rawRespondent === 'keep') {
            $respondentName = $item->getRespondent();
        } elseif ($rawRespondent === 'codir') {
            $respondentName = BoardAgendaItem::RESPONDENT_CODIR;
        } else {
            $respondent = $this->resolveParticipant($rawRespondent);
            if ($respondent === false) {
                $this->addFlash('danger', 'Qui répond ? Choisissez le CoDir, un membre du CoDir ou un entraîneur invité : la réponse n\'a pas été enregistrée.');
                return $this->backTo($item->getMeeting(), 'item-'.$item->getId());
            }
            $respondentName = $respondent?->getFullName();
        }
        // « Porteur action » (texte libre) n'est plus saisi que sur les anciens points : sans le champ, on le conserve.
        $item->recordAnswer(
            $respondentName,
            (string) $request->request->get('answer', ''),
            $request->request->has('actionOwner') ? (string) $request->request->get('actionOwner', '') : $item->getActionOwner(),
        );
        $this->em->flush();
        $this->addFlash('success', 'Réponse enregistrée.');

        return $this->backTo($item->getMeeting(), 'item-'.$item->getId());
    }

    /**
     * Ajout d'un point depuis le back-office, avant la séance (ordre du jour préparé par le secrétaire, un administrateur…)
     * comme pendant (point soulevé en séance), pour le compte de « Qui s'exprime ? » : un membre du CoDir ou un
     * entraîneur invité.
     */
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
        if (mb_strlen($text) > BoardAgendaItem::TEXT_MAX) {
            $this->addFlash('danger', sprintf('Texte trop long (%d caractères max) : le point n\'a pas été ajouté.', BoardAgendaItem::TEXT_MAX));
            return $this->backTo($meeting);
        }
        $speaker = $this->resolveParticipant((string) $request->request->get('speaker', ''));
        if ($speaker === false) {
            $this->addFlash('danger', 'Qui s\'exprime ? Seul un membre du CoDir ou un entraîneur invité peut être choisi : le point n\'a pas été ajouté.');
            return $this->backTo($meeting);
        }
        /** @var User $user */
        $user = $this->getUser();
        $item = new BoardAgendaItem($meeting, $pole, $type, $text, $user);
        $item->setSpeaker($speaker?->getFullName());
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
        // « Qui s'exprime ? » : « keep » conserve l'intervenant actuel (ancienne saisie libre non reconnue dans la liste).
        $rawSpeaker = trim((string) $request->request->get('speaker', 'keep'));
        $speaker = $rawSpeaker === 'keep' ? null : $this->resolveParticipant($rawSpeaker);
        if ($speaker === false) {
            $this->addFlash('danger', 'Qui s\'exprime ? Seul un membre du CoDir ou un entraîneur invité peut être choisi : le point n\'a pas été modifié.');
            return $this->backTo($meeting, 'item-'.$item->getId());
        }
        $item->setPole($pole)->setType($type)->setText($text);
        if ($rawSpeaker !== 'keep') {
            $item->setSpeaker($speaker?->getFullName());
        }
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

    /**
     * Présences de la réunion : membres du CoDir / entraîneurs cochés « Présent » ou « Excusé » (champs `present[]` et
     * `excused[]`, parmi les comptes affichés `listed[]`) et invités (`guests[]` = comptes — adhérents, parents,
     * comptes externes… —, `guest_free_name[]` / `guest_free_note[]` = personnes sans compte, avec leur qualité).
     * Le formulaire envoie tout l'état : les présences des comptes affichés et tous les invités sont remplacés ; celles
     * des comptes qu'il n'affiche pas sont conservées. Reprises dans le compte rendu.
     */
    #[Route('/admin/codir/meetings/{id}/presences', name: 'admin_board_attendance_save', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function saveAttendance(int $id, Request $request): RedirectResponse
    {
        $this->validateCsrf($request);
        $meeting = $this->meetings->find($id);
        if ($meeting === null) {
            throw $this->createNotFoundException();
        }
        $listed = $this->idsFromRequest($request, 'listed', 300);
        $present = array_flip($this->idsFromRequest($request, 'present', 300));
        $excused = array_flip($this->idsFromRequest($request, 'excused', 300));
        $guestIds = $this->idsFromRequest($request, 'guests', 60);
        $users = [];
        $wanted = array_values(array_unique([...$listed, ...$guestIds]));
        foreach ($wanted === [] ? [] : $this->users->findBy(['id' => $wanted]) as $u) {
            $users[$u->getId()] = $u;
        }

        // 1. Retire ce que le formulaire remplace : tous les invités, et le statut des comptes affichés.
        $listedSet = array_flip($listed);
        foreach ($meeting->getAttendees()->toArray() as $a) {
            $uid = $a->getUser()?->getId();
            if ($a->getStatus() === BoardAttendance::Guest || ($uid !== null && isset($listedSet[$uid]))) {
                $meeting->removeAttendee($a);
                $this->em->remove($a);
            }
        }
        // 2. Présents / excusés (« Présent » l'emporte si les deux sont cochés).
        $taken = [];
        foreach ($listed as $uid) {
            $user = $users[$uid] ?? null;
            if ($user === null) {
                continue;
            }
            $status = isset($present[$uid]) ? BoardAttendance::Present : (isset($excused[$uid]) ? BoardAttendance::Excused : null);
            if ($status !== null) {
                $this->persistAttendee(new BoardMeetingAttendee($meeting, $status, $user, ''), $meeting);
                $taken[$uid] = true;
            }
        }
        // Les présences des comptes non affichés (ancien membre…) restent : un invité ne les double pas.
        foreach ($meeting->getAttendees() as $a) {
            if ($a->getUser() !== null && $a->getStatus() !== BoardAttendance::Guest) {
                $taken[$a->getUser()->getId()] = true;
            }
        }
        // 3. Invités ayant un compte, puis invités sans compte (nom libre + qualité facultative).
        foreach ($guestIds as $uid) {
            $user = $users[$uid] ?? null;
            if ($user !== null && !isset($taken[$uid])) {
                $this->persistAttendee(new BoardMeetingAttendee($meeting, BoardAttendance::Guest, $user, ''), $meeting);
                $taken[$uid] = true;
            }
        }
        $names = array_values($request->request->all('guest_free_name'));
        $notes = array_values($request->request->all('guest_free_note'));
        foreach (array_slice($names, 0, 60) as $i => $rawName) {
            $name = is_string($rawName) ? trim($rawName) : '';
            if ($name === '') {
                continue;
            }
            $note = isset($notes[$i]) && is_string($notes[$i]) ? $notes[$i] : null;
            $this->persistAttendee(new BoardMeetingAttendee($meeting, BoardAttendance::Guest, null, $name, $note), $meeting);
        }
        $this->em->flush();

        $counts = [count($meeting->getPresent()), count($meeting->getExcused()), count($meeting->getGuests())];
        $this->addFlash('success', sprintf(
            'Présences enregistrées : %d présent%s, %d excusé%s, %d invité%s.',
            $counts[0], $counts[0] > 1 ? 's' : '',
            $counts[1], $counts[1] > 1 ? 's' : '',
            $counts[2], $counts[2] > 1 ? 's' : '',
        ));

        return $this->backTo($meeting, 'presences');
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

    /**
     * Recherche (nom ou prénom) parmi tous les comptes actifs — adhérents, entraîneurs, comptes externes — pour désigner
     * un porteur d'action qui ne figure pas dans les listes CoDir / entraîneurs : un porteur peut être n'importe qui.
     */
    #[Route('/admin/codir/adherents', name: 'admin_board_adherents', methods: ['GET'])]
    public function searchAdherents(Request $request): JsonResponse
    {
        $q = trim((string) $request->query->get('q', ''));
        if (mb_strlen($q) < 2) {
            return new JsonResponse(['data' => []]);
        }

        return new JsonResponse(['data' => array_map(
            static fn (User $u) => ['id' => $u->getId(), 'name' => $u->getFullName()],
            $this->users->searchActiveAccounts($q, 12),
        )]);
    }

    /**
     * Compte rendu de la réunion, généré à partir de tous les points : commentaires et réponses des membres,
     * réponse / décision de la séance, actions et porteurs, puis le tableau récapitulatif des actions.
     * Page autonome, prête à imprimer ou à enregistrer en PDF depuis le navigateur. Elle se termine par la signature du
     * Secrétaire (le compte qui a ce rôle CODIR ; sa signature se paramètre sur sa fiche utilisateur).
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

        // Réunion close avant la mise en service du figeage : on fige le Secrétaire en fonction dès la première consultation.
        if ($this->signatures->freezeIfMissing($meeting)) {
            $this->em->flush();
        }

        return $this->render('admin/board_minutes.html.twig', [
            'meeting' => $meeting,
            'groups' => $groups,
            'commentCount' => $commentCount,
            'decidedCount' => $decidedCount,
            // Suivi : toutes les actions en cours à la date de la réunion (nouvelles et reportées des précédentes).
            'openActions' => $this->actions->findOpenAsOf($meeting),
            // Signataire(s) du compte rendu (insérés automatiquement au bas du document) : ceux figés à la clôture de la
            // réunion, sinon le ou les comptes ayant actuellement le rôle Secrétaire.
            'secretaries' => $this->signatures->signersFor($meeting),
            'signersFrozen' => $meeting->hasFrozenSigners(),
        ]);
    }

    /**
     * (Ré)envoie l'invitation par un événement Google Agenda — pour une réunion programmée avant
     * la mise en service de Google Agenda, ou pour renvoyer l'invitation (`replace` : l'ancien événement est supprimé et
     * un nouveau créé). Administrateurs uniquement ; l'envoi est asynchrone.
     */
    #[IsGranted('ROLE_ADMIN')]
    #[Route('/admin/codir/meetings/{id}/calendar', name: 'admin_board_meeting_calendar', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function inviteCalendar(int $id, Request $request): RedirectResponse
    {
        $this->validateCsrf($request);
        $meeting = $this->meetings->find($id);
        if ($meeting === null) {
            throw $this->createNotFoundException();
        }
        if (!$this->calendar->isConfigured()) {
            $this->addFlash('danger', 'Google Agenda n\'est pas configuré : aucune invitation n\'a été envoyée (voir docs/GOOGLE-CALENDAR.md).');
        } elseif (!$meeting->isUpcoming()) {
            $this->addFlash('danger', 'Cette réunion est déjà passée : on n\'invite pas à une réunion passée.');
        } else {
            $this->bus->dispatch(new CreateBoardMeetingCalendarEventMessage((int) $meeting->getId(), $request->request->get('replace') !== null));
            $this->addFlash('success', 'Invitation Google Agenda en cours d\'envoi.');
        }

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
        $wasClosed = $meeting->isClosed();
        $meeting->setClosed($request->request->get('reopen') === null);
        // Clôture : le Secrétaire en fonction est figé sur le compte rendu ; réouverture : on le défige.
        $this->signatures->syncWithClosure($meeting, $wasClosed);
        $this->em->flush();
        if (!$meeting->isClosed()) {
            $this->addFlash('success', 'Réunion rouverte.');
        } elseif ($wasClosed) {
            $this->addFlash('success', 'Réunion déjà close.');
        } else {
            // Seule la transition ouverte → close prévient les membres (pas un double clic ni une page périmée).
            $user = $this->getUser();
            $sent = $this->notifier->notifyClosed($meeting, $user instanceof User ? $user : null);
            $this->addFlash('success', 'Réunion close : le compte rendu est consultable dans l\'appli, les membres du CoDir et les entraîneurs en sont prévenus.');
            if (!$sent) {
                $this->addFlash('warning', 'La notification aux membres n\'a pas pu être envoyée (voir le journal du serveur).');
            }
        }

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
            // Présences (comme dans le compte rendu) : une ligne par statut renseigné.
            $names = static fn (array $list, bool $withNote = false): string => implode(', ', array_map(
                static fn (BoardMeetingAttendee $a) => $a->getDisplayName().($withNote && $a->getNote() !== null ? ' ('.$a->getNote().')' : ''),
                $list,
            ));
            foreach ([['Présents', $meeting->getPresent(), false], ['Excusés', $meeting->getExcused(), false], ['Invités', $meeting->getGuests(), true]] as [$title, $list, $withNote]) {
                if ($list !== []) {
                    fputcsv($out, [$title.' : '.$names($list, $withNote)], ';');
                }
            }
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

    /** Identifiants entiers positifs, sans doublon, d'un champ tableau du formulaire (au plus `$max`). @return list<int> */
    private function idsFromRequest(Request $request, string $field, int $max): array
    {
        $ids = [];
        foreach ($request->request->all($field) as $raw) {
            $id = is_scalar($raw) ? (int) $raw : 0;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        return array_slice(array_values($ids), 0, $max);
    }

    private function persistAttendee(BoardMeetingAttendee $attendee, BoardMeeting $meeting): void
    {
        $meeting->addAttendee($attendee);
        $this->em->persist($attendee);
    }

    /**
     * Personnes proposées dans le formulaire de présences : membres du CoDir, entraîneurs invités, puis les autres
     * comptes déjà marqués présents / excusés (ancien membre…) pour qu'on puisse encore les modifier.
     *
     * @param list<User> $codir
     * @param list<User> $coaches
     * @return list<array{label: string, users: list<User>}>
     */
    private function attendanceGroups(BoardMeeting $meeting, array $codir, array $coaches): array
    {
        $groups = [['label' => 'Comité directeur', 'users' => $codir]];
        if ($coaches !== []) {
            $groups[] = ['label' => 'Entraîneurs invités', 'users' => $coaches];
        }
        $known = [];
        foreach ([...$codir, ...$coaches] as $u) {
            $known[$u->getId()] = true;
        }
        $others = [];
        foreach ($meeting->getAttendees() as $a) {
            $u = $a->getUser();
            if ($u !== null && $a->getStatus() !== BoardAttendance::Guest && !isset($known[$u->getId()])) {
                $others[$u->getId()] = $u;
            }
        }
        if ($others !== []) {
            $others = array_values($others);
            usort($others, static fn (User $a, User $b) => strcasecmp($a->getNom().' '.$a->getPrenom(), $b->getNom().' '.$b->getPrenom()));
            $groups[] = ['label' => 'Autres participants', 'users' => $others];
        }

        return $groups;
    }

    /** @return array<int, string> compte => « present » | « excused » (les invités n'y figurent pas) */
    private function attendanceStatuses(BoardMeeting $meeting): array
    {
        $out = [];
        foreach ($meeting->getAttendees() as $a) {
            if ($a->getUser() !== null && $a->getStatus() !== BoardAttendance::Guest) {
                $out[$a->getUser()->getId()] = $a->getStatus()->value;
            }
        }

        return $out;
    }

    /**
     * « Qui s'exprime ? » et « Qui répond ? » : identifiant d'un compte actif membre du CoDir ou entraîneur. Retourne ce
     * compte, null si le champ est vide (personne), false si la valeur n'est pas un compte autorisé à s'exprimer.
     */
    private function resolveParticipant(string $raw): User|null|false
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        $user = ctype_digit($raw) ? $this->users->find((int) $raw) : null;

        return $user instanceof User && $user->isActive() && $user->canSpeakAtBoard() ? $user : false;
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
