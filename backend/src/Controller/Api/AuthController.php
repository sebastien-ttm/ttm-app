<?php

namespace App\Controller\Api;

use App\EventListener\AuthSuccessListener;
use App\Message\SendMagicLinkEmailMessage;
use App\Repository\UserRepository;
use App\Service\MagicLinkService;
use Gesdinet\JWTRefreshTokenBundle\Generator\RefreshTokenGeneratorInterface;
use Gesdinet\JWTRefreshTokenBundle\Model\RefreshTokenManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Messenger\MessageBusInterface;

class AuthController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly MagicLinkService $magicLinks,
        private readonly JWTTokenManagerInterface $jwt,
        private readonly RefreshTokenGeneratorInterface $refreshTokenGenerator,
        private readonly RefreshTokenManagerInterface $refreshTokenManager,
        private readonly MessageBusInterface $bus,
    ) {
    }

    #[Route('/api/auth/magic-link/request', methods: ['POST'])]
    public function requestMagicLink(
        Request $request,
        RateLimiterFactory $magicLinkRequestIpLimiter,
        RateLimiterFactory $magicLinkRequestEmailLimiter,
    ): JsonResponse {
        $payload = json_decode($request->getContent(), true);
        $email = is_array($payload) ? trim((string) ($payload['email'] ?? '')) : '';

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return new JsonResponse(['error' => 'Email invalide.'], Response::HTTP_BAD_REQUEST);
        }

        // Rate limit by IP first (cheap)
        $ipLimiter = $magicLinkRequestIpLimiter->create($request->getClientIp() ?? 'unknown');
        if (!$ipLimiter->consume()->isAccepted()) {
            return new JsonResponse(['error' => 'Trop de demandes. Réessayez plus tard.'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        // Then by email (to slow targeted attacks)
        $emailLimiter = $magicLinkRequestEmailLimiter->create(mb_strtolower($email, 'UTF-8'));
        if (!$emailLimiter->consume()->isAccepted()) {
            return new JsonResponse(['error' => 'Trop de demandes pour cet e-mail.'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $user = $this->users->findOneByEmail($email);

        // Always return 204 to avoid leaking which emails exist
        if ($user !== null && $user->isActive()) {
            $issued = $this->magicLinks->issue($user);
            $this->bus->dispatch(new SendMagicLinkEmailMessage(
                userId: $user->getId(),
                clearToken: $issued['token'],
                isWelcome: false,
            ));
        }

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/api/auth/magic-link/verify', methods: ['GET', 'POST'])]
    public function verifyMagicLink(Request $request): JsonResponse
    {
        $token = (string) ($request->query->get('token') ?? json_decode($request->getContent(), true)['token'] ?? '');
        if ($token === '') {
            return new JsonResponse(['error' => 'Token manquant.'], Response::HTTP_BAD_REQUEST);
        }

        $user = $this->magicLinks->consume($token);
        if ($user === null) {
            return new JsonResponse(['error' => 'Lien invalide ou expiré.'], Response::HTTP_UNAUTHORIZED);
        }
        if (!$user->isActive()) {
            return new JsonResponse(['error' => 'Compte désactivé.'], Response::HTTP_FORBIDDEN);
        }

        $accessToken = $this->jwt->create($user);
        $refresh = $this->refreshTokenGenerator->createForUserWithTtl($user, 2592000);
        $this->refreshTokenManager->save($refresh);

        // IMPORTANT : le champ "token" doit avoir la même casse/clé que la
        // réponse du login JSON Lexik, sinon les clients (mobile) ne savent
        // pas où lire le JWT et tombent en silencieux avec un "undefined"
        // stocké en localStorage.
        return new JsonResponse([
            'token' => $accessToken,
            'refresh_token' => $refresh->getRefreshToken(),
            'user' => AuthSuccessListener::serializeUser($user),
        ]);
    }
}
