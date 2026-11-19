<?php

namespace App\Controller\Api;

use App\Service\Event\EventOrderService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Adresse de notification (« webhook ») à déclarer dans HelloAsso : Mon compte > Intégrations et API > Notifications.
 * Publique (HelloAsso n'est pas un utilisateur de l'appli) et non signée : son contenu n'est jamais cru. Il sert
 * seulement à retrouver la commande ; le paiement est ensuite vérifié par un appel authentifié à l'API HelloAsso.
 *
 * Répond toujours 200 : HelloAsso réessaie sinon, et une notification inconnue (autre boutique de l'association) n'est
 * pas une erreur.
 */
class HelloAssoNotificationController extends AbstractController
{
    public function __construct(
        private readonly EventOrderService $orders,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/api/public/helloasso/notifications', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $raw = $request->getContent();
        $payload = json_decode($raw ?: '{}', true);
        if (!is_array($payload)) {
            return new JsonResponse(['ok' => true]);
        }

        try {
            $known = $this->orders->handleNotification($payload);
            // Contenu tronqué dans les journaux : sert à vérifier la forme réelle des notifications HelloAsso.
            $this->logger->info('HelloAsso : notification reçue', [
                'type' => is_string($payload['eventType'] ?? null) ? $payload['eventType'] : null,
                'known' => $known,
                'body' => mb_substr($raw, 0, 2000),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('HelloAsso : notification non traitée', ['error' => $e->getMessage()]);
        }

        return new JsonResponse(['ok' => true]);
    }
}
