<?php

namespace App\Controller\Api;

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
use App\Security\BoardSessionVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Ordre du jour du comité directeur (BOARD_AGENDA) : membres du CoDir, administrateurs et entraîneurs (invités aux
 * réunions), tous avec les mêmes droits en lecture ET en écriture. La page « En séance » du back-office
 * (BOARD_SESSION) reste réservée aux membres du CoDir et aux administrateurs.
 *  - liste de toutes les réunions (à venir puis passées) ;
 *  - détail d'une réunion : points classés par pôle, avec les réponses / décisions saisies en séance ;
 *  - ajout d'un point (pôle, Information / Sujet / Question, texte) tant que la réunion n'est pas close ;
 *  - modification / suppression de ses propres points tant qu'ils n'ont pas été traités ;
 *  - commentaires (réponses, pour une Question) sur n'importe quel point, sans limite de nombre, et réponses aux
 *    commentaires (fil) tant que la réunion est ouverte ;
 *  - lecture des actions confiées en séance (saisies dans le back-office), avec échéance et état : la liste des
 *    réunions porte toutes les actions en cours, celles du membre connecté étant repérées (`isMine`).
 */
#[IsGranted(BoardSessionVoter::AGENDA)]
#[Route('/api/board')]
class BoardMeetingController extends AbstractController
{
    public function __construct(
        private readonly BoardMeetingRepository $meetings,
        private readonly BoardAgendaItemRepository $items,
        private readonly BoardActionRepository $actions,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/meetings', methods: ['GET'])]
    public function list(): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $this->getUser();
        $next = $this->meetings->findNext();
        return new JsonResponse([
            'data' => array_map(fn (BoardMeeting $m) => $this->summary($m, $next), $this->meetings->findAllForListing()),
            'nextId' => $next?->getId(),
            // Actions en cours de toutes les réunions, échéance la plus proche d'abord.
            'actions' => array_map(fn (BoardAction $a) => $this->actionPayload($a, $viewer) + [
                'meetingId' => $a->getItem()->getMeeting()->getId(),
                'meetingLabel' => $a->getItem()->getMeeting()->getLabel(),
                'pole' => $a->getItem()->getPole()->value,
                'poleLabel' => $a->getItem()->getPole()->label(),
                'pointText' => mb_strimwidth($a->getItem()->getText(), 0, 140, '…'),
            ], $this->actions->findOpen()),
            'poles' => array_map(static fn (BoardPole $p) => ['value' => $p->value, 'label' => $p->label()], BoardPole::cases()),
            'types' => array_map(static fn (BoardItemType $t) => ['value' => $t->value, 'label' => $t->label()], BoardItemType::cases()),
        ]);
    }

    #[Route('/meetings/{id}', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function detail(int $id): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $this->getUser();
        return new JsonResponse($this->detailPayload($this->findMeetingOr404($id), $viewer));
    }

    /** Ajout d'un point. JSON : { pole, type, text }. */
    #[Route('/meetings/{id}/items', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function addItem(int $id, Request $request): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $this->getUser();
        $meeting = $this->findMeetingOr404($id);
        if ($meeting->isClosed()) {
            return new JsonResponse(['error' => 'Cette réunion est close : l\'ordre du jour ne peut plus être modifié.'], Response::HTTP_CONFLICT);
        }
        $input = $this->parseInput($request);
        if (is_string($input)) {
            return new JsonResponse(['error' => $input], Response::HTTP_BAD_REQUEST);
        }

        $item = new BoardAgendaItem($meeting, $input['pole'], $input['type'], $input['text'], $viewer);
        $meeting->addItem($item);
        $this->em->persist($item);
        $this->em->flush();

        return new JsonResponse($this->detailPayload($meeting, $viewer), Response::HTTP_CREATED);
    }

    /** Modification de son propre point (non traité, réunion ouverte). JSON : { pole, type, text }. */
    #[Route('/items/{id}', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    public function updateItem(int $id, Request $request): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $this->getUser();
        $item = $this->findEditableItemOr404($id, $viewer);
        $input = $this->parseInput($request);
        if (is_string($input)) {
            return new JsonResponse(['error' => $input], Response::HTTP_BAD_REQUEST);
        }
        $item->setPole($input['pole'])->setType($input['type'])->setText($input['text']);
        $this->em->flush();

        return new JsonResponse($this->detailPayload($item->getMeeting(), $viewer));
    }

    #[Route('/items/{id}', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function deleteItem(int $id): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $this->getUser();
        $item = $this->findEditableItemOr404($id, $viewer);
        $meeting = $item->getMeeting();
        $meeting->removeItem($item);
        $this->em->remove($item);
        $this->em->flush();

        return new JsonResponse($this->detailPayload($meeting, $viewer));
    }

    /**
     * Commentaire (ou réponse, pour une Question) sur un point — le sien compris, pour répondre à ceux qui l'ont
     * commenté — sans limite de nombre. JSON : { text, parentId? } ; `parentId` = commentaire du même point auquel on répond.
     */
    #[Route('/items/{id}/comments', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function addComment(int $id, Request $request): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $this->getUser();
        $item = $this->items->find($id);
        if ($item === null) {
            throw $this->createNotFoundException('Point introuvable.');
        }
        $meeting = $item->getMeeting();
        if ($meeting->isClosed()) {
            return new JsonResponse(['error' => 'Cette réunion est close : les commentaires ne sont plus ouverts.'], Response::HTTP_CONFLICT);
        }
        if (!$item->isCommentableBy($viewer)) {
            return new JsonResponse(['error' => 'Les commentaires ne sont pas ouverts sur ce point.'], Response::HTTP_FORBIDDEN);
        }
        $payload = json_decode($request->getContent(), true);
        $text = is_array($payload) ? trim((string) ($payload['text'] ?? '')) : '';
        if ($text === '') {
            return new JsonResponse(['error' => 'Écrivez votre message.'], Response::HTTP_BAD_REQUEST);
        }
        if (mb_strlen($text) > BoardItemComment::TEXT_MAX) {
            return new JsonResponse(['error' => sprintf('Message trop long (%d caractères max).', BoardItemComment::TEXT_MAX)], Response::HTTP_BAD_REQUEST);
        }

        $parent = null;
        $parentId = is_array($payload) ? ($payload['parentId'] ?? null) : null;
        if ($parentId !== null && $parentId !== '') {
            $parent = is_numeric($parentId) ? $this->em->getRepository(BoardItemComment::class)->find((int) $parentId) : null;
            if ($parent === null || $parent->getItem()->getId() !== $item->getId()) {
                return new JsonResponse(['error' => 'Le commentaire auquel vous répondez n\'existe plus.'], Response::HTTP_BAD_REQUEST);
            }
        }

        $comment = new BoardItemComment($item, $viewer, $text, $parent);
        $item->addComment($comment);
        $this->em->persist($comment);
        $this->em->flush();

        return new JsonResponse($this->detailPayload($meeting, $viewer), Response::HTTP_CREATED);
    }

    /** Suppression de son propre commentaire tant que la réunion est ouverte et que personne n'y a répondu. */
    #[Route('/comments/{id}', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function deleteComment(int $id): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $this->getUser();
        $comment = $this->em->getRepository(BoardItemComment::class)->find($id);
        if ($comment === null) {
            throw $this->createNotFoundException('Commentaire introuvable.');
        }
        $item = $comment->getItem();
        $meeting = $item->getMeeting();
        if ($meeting->isClosed() || !$comment->isAuthoredBy($viewer)) {
            throw $this->createAccessDeniedException('Seul l\'auteur peut supprimer son commentaire, tant que la réunion est ouverte.');
        }
        if ($item->hasReplies($comment)) {
            return new JsonResponse(['error' => 'Ce commentaire a reçu des réponses : il ne peut plus être supprimé.'], Response::HTTP_CONFLICT);
        }
        $item->removeComment($comment);
        $this->em->remove($comment);
        $this->em->flush();

        return new JsonResponse($this->detailPayload($meeting, $viewer));
    }

    /**
     * @return array{pole: BoardPole, type: BoardItemType, text: string}|string  message d'erreur si invalide
     */
    private function parseInput(Request $request): array|string
    {
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return 'Corps invalide.';
        }
        $pole = BoardPole::tryFrom((string) ($payload['pole'] ?? ''));
        if ($pole === null) {
            return 'Choisissez un pôle.';
        }
        $type = BoardItemType::tryFrom((string) ($payload['type'] ?? ''));
        if ($type === null) {
            return 'Choisissez : Information, Sujet ou Question.';
        }
        $text = trim((string) ($payload['text'] ?? ''));
        if ($text === '') {
            return 'Le texte du point est vide.';
        }
        if (mb_strlen($text) > BoardAgendaItem::TEXT_MAX) {
            return sprintf('Texte trop long (%d caractères max).', BoardAgendaItem::TEXT_MAX);
        }
        return ['pole' => $pole, 'type' => $type, 'text' => $text];
    }

    /** @return array<string, mixed> */
    private function summary(BoardMeeting $m, ?BoardMeeting $next): array
    {
        return [
            'id' => $m->getId(),
            'label' => $m->getLabel(),
            'scheduledAt' => $m->getScheduledAt()->format(\DATE_ATOM),
            'location' => $m->getLocation(),
            'closed' => $m->isClosed(),
            'isNext' => $next !== null && $next->getId() === $m->getId(),
            'itemCount' => $m->getItemCount(),
            'answeredCount' => $m->getAnsweredCount(),
        ];
    }

    /** @return array<string, mixed> */
    private function detailPayload(BoardMeeting $m, User $viewer): array
    {
        $next = $this->meetings->findNext();
        return $this->summary($m, $next) + [
            'notes' => $m->getNotes(),
            'canAddItems' => !$m->isClosed(),
            'items' => array_map(fn (BoardAgendaItem $i) => [
                'id' => $i->getId(),
                'pole' => $i->getPole()->value,
                'poleLabel' => $i->getPole()->label(),
                'type' => $i->getType()->value,
                'typeLabel' => $i->getType()->label(),
                'text' => $i->getText(),
                'speaker' => $i->getSpeaker(),
                'isMine' => $i->getAuthor() !== null && $i->getAuthor()->getId() === $viewer->getId(),
                'canEdit' => $i->isEditableBy($viewer),
                'respondent' => $i->getRespondent(),
                'answer' => $i->getAnswer(),
                'actionOwner' => $i->getActionOwner(),
                'answered' => $i->isAnswered(),
                'createdAt' => $i->getCreatedAt()->format(\DATE_ATOM),
                'canComment' => $i->isCommentableBy($viewer),
                // En fil : chaque commentaire suivi de ses réponses ; `depth` = indentation (0 à 2), `replyToName` = auteur
                // du commentaire auquel on répond.
                'comments' => array_map(static fn (array $t) => [
                    'id' => $t['comment']->getId(),
                    'parentId' => $t['comment']->getParent()?->getId(),
                    'depth' => $t['depth'],
                    'replyToName' => $t['replyTo'],
                    'authorName' => $t['comment']->getAuthorName(),
                    'text' => $t['comment']->getText(),
                    'isMine' => $t['comment']->isAuthoredBy($viewer),
                    'canDelete' => !$i->getMeeting()->isClosed() && $t['comment']->isAuthoredBy($viewer) && !$i->hasReplies($t['comment']),
                    'createdAt' => $t['comment']->getCreatedAt()->format(\DATE_ATOM),
                ], $i->getThreadedComments()),
                'actions' => array_map(fn (BoardAction $a) => $this->actionPayload($a, $viewer), $i->getActions()->toArray()),
            ], $m->getSortedItems()),
        ];
    }

    /**
     * Une action : texte, porteurs, échéance (AAAA-MM-JJ), état (en cours / clôturée, en retard) et `isMine` si le
     * membre connecté en est l'un des porteurs.
     *
     * @return array<string, mixed>
     */
    private function actionPayload(BoardAction $a, User $viewer): array
    {
        return [
            'id' => $a->getId(),
            'text' => $a->getText(),
            'assignees' => $a->getAssigneeNames(),
            'dueDate' => $a->getDueDate()?->format('Y-m-d'),
            'closed' => $a->isClosed(),
            'closedAt' => $a->getClosedAt()?->format(\DATE_ATOM),
            'overdue' => $a->isOverdue(),
            'isMine' => $a->isAssignedTo($viewer),
        ];
    }

    private function findMeetingOr404(int $id): BoardMeeting
    {
        $meeting = $this->meetings->find($id);
        if ($meeting === null) {
            throw $this->createNotFoundException('Réunion introuvable.');
        }
        return $meeting;
    }

    private function findEditableItemOr404(int $id, User $viewer): BoardAgendaItem
    {
        $item = $this->items->find($id);
        if ($item === null) {
            throw $this->createNotFoundException('Point introuvable.');
        }
        if (!$item->isEditableBy($viewer)) {
            throw $this->createAccessDeniedException('Seul l\'auteur peut modifier ce point, tant que la réunion est ouverte et qu\'il n\'a pas été traité.');
        }
        return $item;
    }
}
