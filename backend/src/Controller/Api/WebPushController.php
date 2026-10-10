<?php

namespace App\Controller\Api;

use App\Entity\User;
use App\Entity\WebPushSubscription;
use App\Repository\WebPushSubscriptionRepository;
use App\Service\WebPush\WebPushClient;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Notifications push web : l'appli (PWA) y enregistre l'abonnement du
 * navigateur de l'adhérent connecté. L'envoi lui-même est fait par
 * WebPushNotifier / SendWebPushMessageHandler.
 */
#[IsGranted('ROLE_USER')]
class WebPushController extends AbstractController
{
    public function __construct(
        private readonly WebPushSubscriptionRepository $subscriptions,
        private readonly WebPushClient $client,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** Clé publique VAPID dont le navigateur a besoin pour s'abonner (enabled=false si non configuré). */
    #[Route('/api/me/push/config', methods: ['GET'])]
    public function config(): JsonResponse
    {
        return new JsonResponse([
            'enabled' => $this->client->isConfigured(),
            'publicKey' => $this->client->isConfigured() ? $this->client->getPublicKey() : null,
        ]);
    }

    /**
     * Body : { endpoint: string, keys: { p256dh: string, auth: string } } — tel que
     * renvoyé par PushSubscription.toJSON(). Idempotent : un appareil déjà connu
     * est simplement rafraîchi (et rattaché à l'adhérent connecté).
     */
    #[Route('/api/me/push/subscriptions', methods: ['POST'])]
    public function subscribe(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return new JsonResponse(['error' => 'Corps invalide.'], Response::HTTP_BAD_REQUEST);
        }
        if (!$this->client->isConfigured()) {
            return new JsonResponse(['error' => 'Notifications push non configurées sur le serveur.'], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $endpoint = trim((string) ($payload['endpoint'] ?? ''));
        $p256dh = trim((string) ($payload['keys']['p256dh'] ?? ''));
        $auth = trim((string) ($payload['keys']['auth'] ?? ''));

        if ($endpoint === '' || strlen($endpoint) > 2048 || !str_starts_with($endpoint, 'https://')) {
            return new JsonResponse(['error' => 'endpoint invalide.'], Response::HTTP_BAD_REQUEST);
        }
        // Le serveur enverra des requêtes à cette adresse : uniquement les services push des navigateurs.
        if (!self::isKnownPushService($endpoint)) {
            return new JsonResponse(['error' => 'Service de notification non reconnu.'], Response::HTTP_BAD_REQUEST);
        }
        $publicKey = WebPushClient::b64uDecode($p256dh);
        if (strlen($publicKey) !== 65 || $publicKey[0] !== "\x04") {
            return new JsonResponse(['error' => 'keys.p256dh invalide.'], Response::HTTP_BAD_REQUEST);
        }
        if (strlen(WebPushClient::b64uDecode($auth)) !== 16) {
            return new JsonResponse(['error' => 'keys.auth invalide.'], Response::HTTP_BAD_REQUEST);
        }

        $userAgent = $request->headers->get('User-Agent');
        $existing = $this->subscriptions->findOneByEndpoint($endpoint);
        if ($existing !== null) {
            $existing->refresh($user, $p256dh, $auth, $userAgent);
        } else {
            $this->em->persist(new WebPushSubscription($user, $endpoint, $p256dh, $auth, $userAgent));
        }
        $this->em->flush();

        return new JsonResponse(['ok' => true], Response::HTTP_CREATED);
    }

    /**
     * Envoie TOUT DE SUITE (sans passer par la file) une notification de test à
     * mes appareils abonnés — pour vérifier que la chaîne fonctionne de bout en bout.
     */
    #[Route('/api/me/push/test', methods: ['POST'])]
    public function test(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        if (!$this->client->isConfigured()) {
            return new JsonResponse(['error' => 'Notifications push non configurées sur le serveur.'], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $subscriptions = $this->subscriptions->findByUserIds([(int) $user->getId()]);
        $payload = (string) json_encode([
            'title' => 'Notification de test',
            'body' => 'Les notifications fonctionnent sur cet appareil.',
            'url' => '/',
            'tag' => 'push-test',
            'icon' => '/icons/icon-192.png?v=2',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        // TTL court : un test n'a aucun intérêt s'il arrive des heures plus tard.
        $results = $this->client->sendMany($subscriptions, $payload, 600);

        $sent = 0;
        $failed = 0;
        foreach ($subscriptions as $subscription) {
            $result = $results[(int) $subscription->getId()] ?? WebPushClient::RESULT_ERROR;
            if ($result === WebPushClient::RESULT_OK) {
                $sent++;
                $subscription->touch();
            } else {
                $failed++;
                if ($result === WebPushClient::RESULT_GONE) {
                    $this->em->remove($subscription);
                }
            }
        }
        $this->em->flush();

        return new JsonResponse(['sent' => $sent, 'failed' => $failed]);
    }

    /**
     * Services push des navigateurs : Chrome / Edge / Opera / Samsung (FCM), Firefox
     * (Mozilla), Safari (Apple), anciens Edge (WNS). Anti-SSRF : on ne contacte jamais
     * une adresse arbitraire fournie par un utilisateur.
     */
    private const PUSH_SERVICE_HOST_SUFFIXES = [
        'googleapis.com',
        'push.services.mozilla.com',
        'push.apple.com',
        'notify.windows.com',
    ];

    private static function isKnownPushService(string $endpoint): bool
    {
        $parts = parse_url($endpoint);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || !isset($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || (isset($parts['port']) && $parts['port'] !== 443)) {
            return false;
        }
        $host = strtolower($parts['host']);
        foreach (self::PUSH_SERVICE_HOST_SUFFIXES as $suffix) {
            if ($host === $suffix || str_ends_with($host, '.'.$suffix)) {
                return true;
            }
        }

        return false;
    }

    /** Body : { endpoint } — retire l'abonnement de CET adhérent (idempotent). */
    #[Route('/api/me/push/subscriptions', methods: ['DELETE'])]
    public function unsubscribe(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $payload = json_decode($request->getContent(), true);
        $endpoint = is_array($payload) ? trim((string) ($payload['endpoint'] ?? '')) : '';
        if ($endpoint !== '') {
            $existing = $this->subscriptions->findOneByEndpoint($endpoint);
            if ($existing !== null && $existing->getUser()->getId() === $user->getId()) {
                $this->em->remove($existing);
                $this->em->flush();
            }
        }

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
