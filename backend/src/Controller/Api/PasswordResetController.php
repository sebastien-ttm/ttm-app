<?php

namespace App\Controller\Api;

use App\Entity\LoginEvent;
use App\EventListener\AuthSuccessListener;
use App\Message\SendPasswordResetEmailMessage;
use App\Repository\UserRepository;
use App\Service\AvatarService;
use App\Service\LoginRecorder;
use App\Service\Membership\MembershipStatusResolver;
use App\Service\PasswordResetService;
use Gesdinet\JWTRefreshTokenBundle\Generator\RefreshTokenGeneratorInterface;
use Gesdinet\JWTRefreshTokenBundle\Model\RefreshTokenManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

/**
 * « Mot de passe oublié », sans connexion (routes sous /api/auth/password-reset, ouvertes
 * par le pare-feu api_public) :
 *  1. request  : l'adhérent saisit son e-mail ; s'il correspond à un compte actif, il reçoit un
 *                lien valable une heure. La réponse est IDENTIQUE que le compte existe ou non
 *                (aucune fuite sur les adresses connues) ;
 *  2. check    : l'appli vérifie le lien avant d'afficher le formulaire ;
 *  3. confirm  : nouveau mot de passe + jeton → mot de passe changé, connexions ouvertes
 *                fermées, et l'adhérent est connecté (même réponse que la connexion par lien).
 */
class PasswordResetController extends AbstractController
{
    private const MAX_PASSWORD_LENGTH = 200;

    public function __construct(
        private readonly UserRepository $users,
        private readonly PasswordResetService $resets,
        private readonly MessageBusInterface $bus,
        private readonly JWTTokenManagerInterface $jwt,
        private readonly RefreshTokenGeneratorInterface $refreshTokenGenerator,
        private readonly RefreshTokenManagerInterface $refreshTokenManager,
        private readonly LoginRecorder $loginRecorder,
        private readonly AvatarService $avatars,
        private readonly MembershipStatusResolver $membershipStatus,
    ) {
    }

    #[Route('/api/auth/password-reset/request', methods: ['POST'])]
    public function request(
        Request $request,
        RateLimiterFactory $passwordResetIpLimiter,
        RateLimiterFactory $passwordResetEmailLimiter,
    ): JsonResponse {
        $payload = json_decode($request->getContent(), true);
        $email = is_array($payload) ? trim((string) ($payload['email'] ?? '')) : '';
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return new JsonResponse(['error' => 'Adresse e-mail invalide.'], Response::HTTP_BAD_REQUEST);
        }

        if (!$passwordResetIpLimiter->create($request->getClientIp() ?? 'unknown')->consume()->isAccepted()) {
            return new JsonResponse(['error' => 'Trop de demandes. Réessayez plus tard.'], Response::HTTP_TOO_MANY_REQUESTS);
        }
        if (!$passwordResetEmailLimiter->create(mb_strtolower($email, 'UTF-8'))->consume()->isAccepted()) {
            return new JsonResponse(['error' => 'Trop de demandes pour cette adresse. Réessayez dans une heure.'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $user = $this->users->findOneByEmail($email);
        // Toujours 204, que le compte existe ou non : on ne révèle pas qui est adhérent.
        if ($user !== null && $user->isActive()) {
            $this->bus->dispatch(new SendPasswordResetEmailMessage((int) $user->getId(), $this->resets->issue($user)));
        }

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    /** Le lien reçu est-il encore valable ? Body : { token }. */
    #[Route('/api/auth/password-reset/check', methods: ['POST'])]
    public function check(Request $request, RateLimiterFactory $passwordResetConfirmIpLimiter): JsonResponse
    {
        if (!$passwordResetConfirmIpLimiter->create($request->getClientIp() ?? 'unknown')->consume()->isAccepted()) {
            return new JsonResponse(['error' => 'Trop de tentatives. Réessayez plus tard.'], Response::HTTP_TOO_MANY_REQUESTS);
        }
        $payload = json_decode($request->getContent(), true);
        $token = is_array($payload) ? (string) ($payload['token'] ?? '') : '';

        return new JsonResponse(['valid' => $this->resets->findUsable($token) !== null]);
    }

    /** Enregistre le nouveau mot de passe. Body : { token, password }. */
    #[Route('/api/auth/password-reset/confirm', methods: ['POST'])]
    public function confirm(Request $request, RateLimiterFactory $passwordResetConfirmIpLimiter): JsonResponse
    {
        if (!$passwordResetConfirmIpLimiter->create($request->getClientIp() ?? 'unknown')->consume()->isAccepted()) {
            return new JsonResponse(['error' => 'Trop de tentatives. Réessayez plus tard.'], Response::HTTP_TOO_MANY_REQUESTS);
        }
        $payload = json_decode($request->getContent(), true);
        $token = is_array($payload) ? (string) ($payload['token'] ?? '') : '';
        $password = is_array($payload) ? (string) ($payload['password'] ?? '') : '';

        $resetToken = $this->resets->findUsable($token);
        if ($resetToken === null) {
            // 400 et non 401 : un 401 ferait croire à l'appli que la session a expiré.
            return new JsonResponse(['error' => 'Ce lien est invalide ou a expiré. Refaites une demande de réinitialisation.'], Response::HTTP_BAD_REQUEST);
        }
        if (mb_strlen($password) < PasswordResetService::MIN_PASSWORD_LENGTH) {
            return new JsonResponse(['error' => sprintf('Le mot de passe doit faire au moins %d caractères.', PasswordResetService::MIN_PASSWORD_LENGTH)], Response::HTTP_BAD_REQUEST);
        }
        if (mb_strlen($password) > self::MAX_PASSWORD_LENGTH) {
            return new JsonResponse(['error' => 'Mot de passe trop long.'], Response::HTTP_BAD_REQUEST);
        }
        if (!$resetToken->getUser()->isActive()) {
            return new JsonResponse(['error' => 'Compte désactivé.'], Response::HTTP_FORBIDDEN);
        }

        $user = $this->resets->complete($resetToken, $password);

        // L'adhérent est connecté tout de suite (même réponse que la connexion par lien).
        $accessToken = $this->jwt->create($user);
        $refresh = $this->refreshTokenGenerator->createForUserWithTtl($user, 2592000);
        $this->refreshTokenManager->save($refresh);
        $this->loginRecorder->record($user, LoginEvent::CHANNEL_MOBILE);

        return new JsonResponse([
            'token' => $accessToken,
            'refresh_token' => $refresh->getRefreshToken(),
            'user' => AuthSuccessListener::serializeUser($user, $this->avatars->urlFor($user), $this->membershipStatus->resolve($user)),
            'linkedProfiles' => AuthSuccessListener::serializeLinkedProfiles($user, $this->users),
        ]);
    }
}
