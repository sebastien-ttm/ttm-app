<?php

namespace App\Controller\Api;

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
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Ordre du jour du comité directeur (membres du CoDir et administrateurs) :
 *  - liste de toutes les réunions (à venir puis passées) ;
 *  - détail d'une réunion : points classés par pôle, avec les réponses / décisions saisies en séance ;
 *  - ajout d'un point (pôle, Information / Sujet / Question, texte) tant que la réunion n'est pas close ;
 *  - modification / suppression de ses propres points tant qu'ils n'ont pas été traités.
 */
#[IsGranted(BoardSessionVoter::ATTRIBUTE)]
#[Route('/api/board')]
class BoardMeetingController extends AbstractController
{
    public function __construct(
        private readonly BoardMeetingRepository $meetings,
        private readonly BoardAgendaItemRepository $items,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/meetings', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $next = $this->meetings->findNext();
        return new JsonResponse([
            'data' => array_map(fn (BoardMeeting $m) => $this->summary($m, $next), $this->meetings->findAllForListing()),
            'nextId' => $next?->getId(),
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
            'items' => array_map(static fn (BoardAgendaItem $i) => [
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
            ], $m->getSortedItems()),
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
