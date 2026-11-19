<?php

namespace App\Controller\Api;

use App\Entity\Event;
use App\Entity\EventOrder;
use App\Entity\User;
use App\Repository\EventOrderRepository;
use App\Repository\EventRepository;
use App\Service\Audience\AudienceFilter;
use App\Service\Event\EventOrderException;
use App\Service\Event\EventOrderService;
use App\Service\HelloAsso\HelloAssoException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Inscription payante à un événement (tarifs + paiement HelloAsso). Toutes les routes sont celles de l'adhérent
 * connecté : une commande n'est jamais accessible à un autre que son auteur.
 */
#[IsGranted('ROLE_USER')]
class EventOrderController extends AbstractController
{
    public function __construct(
        private readonly EventRepository $events,
        private readonly EventOrderRepository $orders,
        private readonly AudienceFilter $audienceFilter,
        private readonly EventOrderService $service,
    ) {
    }

    /**
     * S'inscrire : crée la commande des tarifs choisis et, s'il y a quelque chose à régler, demande la page de paiement à
     * HelloAsso. Body : { "lines": [ { "tariffId": 3, "quantity": 2 }, … ] }
     *
     * Réponse : { order, redirectUrl } — redirectUrl est null quand la commande est déjà payée (total nul).
     */
    #[Route('/api/events/{id}/orders', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function create(int $id, Request $request): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $this->getUser();
        $event = $this->findVisibleOr404($id, $viewer);

        $payload = json_decode($request->getContent() ?: '{}', true);
        $lines = is_array($payload) && is_array($payload['lines'] ?? null) ? $payload['lines'] : [];
        $requested = [];
        foreach ($lines as $line) {
            if (is_array($line)) {
                $requested[] = ['tariffId' => (int) ($line['tariffId'] ?? 0), 'quantity' => (int) ($line['quantity'] ?? 0)];
            }
        }

        try {
            $order = $this->service->createOrder($event, $viewer, $requested);
        } catch (EventOrderException $e) {
            return $this->refusal($e);
        }

        if (!$order->isPending()) {
            return new JsonResponse(['order' => $this->service->serialize($order), 'redirectUrl' => null], Response::HTTP_CREATED);
        }

        try {
            $this->service->startCheckout($order);
        } catch (HelloAssoException $e) {
            // Pas de page de paiement : la commande ne doit pas retenir les places.
            $this->service->cancel($order);

            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_BAD_GATEWAY);
        }

        return new JsonResponse([
            'order' => $this->service->serialize($order),
            'redirectUrl' => $order->getCheckoutUrl(),
        ], Response::HTTP_CREATED);
    }

    /**
     * Statut d'une commande — l'appli l'interroge au retour de HelloAsso. HelloAsso est alors vérifié (jamais le seul
     * paramètre de l'adresse de retour) : la réponse dit si le paiement a abouti.
     */
    #[Route('/api/event-orders/{id}', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id): JsonResponse
    {
        $order = $this->findOwnOr404($id);
        if ($order->isPending()) {
            if ($order->isExpired()) {
                $this->service->settleExpired($order);
            } else {
                $this->service->verify($order);
            }
        }

        return new JsonResponse(['order' => $this->service->serialize($order)]);
    }

    /** Reprendre un paiement en attente : même page HelloAsso si elle est encore valable, sinon une nouvelle. */
    #[Route('/api/event-orders/{id}/pay', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function pay(int $id): JsonResponse
    {
        $order = $this->findOwnOr404($id);
        if ($order->isPending() && $order->isExpired()) {
            $this->service->settleExpired($order);
        }
        if (!$order->isPending() && !$order->isPaid()) {
            return new JsonResponse(['error' => 'Cette inscription n\'est plus en attente de paiement : recommencez-la.'], Response::HTTP_CONFLICT);
        }

        try {
            $alreadyPaid = $this->service->resumeCheckout($order);
        } catch (HelloAssoException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_BAD_GATEWAY);
        }

        return new JsonResponse([
            'order' => $this->service->serialize($order),
            'redirectUrl' => $alreadyPaid ? null : $order->getCheckoutUrl(),
        ]);
    }

    /** Annuler une inscription non payée (les places sont libérées). Une inscription payée ne s'annule pas ici. */
    #[Route('/api/event-orders/{id}/cancel', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function cancel(int $id): JsonResponse
    {
        $order = $this->findOwnOr404($id);
        if ($order->isPaid()) {
            return new JsonResponse(['error' => 'Cette inscription est payée : contactez le club pour la modifier ou la faire rembourser.', 'order' => $this->service->serialize($order)], Response::HTTP_CONFLICT);
        }
        if ($order->isPending() && !$this->service->cancel($order)) {
            // HelloAsso indique que le paiement a finalement abouti.
            return new JsonResponse(['error' => 'Le paiement vient d\'être confirmé : l\'inscription est payée.', 'order' => $this->service->serialize($order)], Response::HTTP_CONFLICT);
        }

        return new JsonResponse(['order' => $this->service->serialize($order)]);
    }

    private function findOwnOr404(int $id): EventOrder
    {
        /** @var User $viewer */
        $viewer = $this->getUser();
        $order = $this->orders->find($id);
        if ($order === null || $order->getUser()?->getId() !== $viewer->getId()) {
            throw $this->createNotFoundException();
        }

        return $order;
    }

    /** Même filet que l'écran de l'événement : un identifiant deviné ne contourne pas l'audience ni le stage. */
    private function findVisibleOr404(int $id, User $viewer): Event
    {
        $event = $this->events->find($id);
        if ($event === null) {
            throw $this->createNotFoundException();
        }
        $stage = $event->getStage();
        if ($stage !== null ? !$stage->isOpenTo($viewer) : !$this->audienceFilter->isVisible($event->getAudience(), $viewer)) {
            throw $this->createNotFoundException();
        }

        return $event;
    }

    private function refusal(EventOrderException $e): JsonResponse
    {
        return new JsonResponse(['error' => $e->getMessage()] + $e->extra, $e->status);
    }
}
