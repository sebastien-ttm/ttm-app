<?php

namespace App\Controller\Api;

use App\Entity\DeviceToken;
use App\Entity\User;
use App\Enum\DevicePlatform;
use App\EventListener\AuthSuccessListener;
use App\Repository\DeviceTokenRepository;
use App\Repository\UserRepository;
use App\Service\AvatarService;
use Doctrine\ORM\EntityManagerInterface;
use Gesdinet\JWTRefreshTokenBundle\Generator\RefreshTokenGeneratorInterface;
use Gesdinet\JWTRefreshTokenBundle\Model\RefreshTokenManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class MeController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DeviceTokenRepository $deviceTokens,
        private readonly UserRepository $users,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly JWTTokenManagerInterface $jwt,
        private readonly RefreshTokenGeneratorInterface $refreshTokenGenerator,
        private readonly RefreshTokenManagerInterface $refreshTokenManager,
        private readonly AvatarService $avatars,
    ) {
    }

    #[Route('/api/me', methods: ['GET'])]
    public function me(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        return new JsonResponse(AuthSuccessListener::serializeUser($user, $this->avatars->urlFor($user)));
    }

    /**
     * Liste des profils accessibles depuis le user courant (lui + ses dépendants
     * ou son primaire + co-dépendants). Vide si pas de profils liés.
     */
    #[Route('/api/me/linked-profiles', methods: ['GET'])]
    public function linkedProfiles(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        return new JsonResponse([
            'data' => AuthSuccessListener::serializeLinkedProfiles($user, $this->users),
        ]);
    }

    /**
     * Bascule vers un autre profil (parent ↔ enfants ↔ frères/sœurs).
     * Le user cible doit appartenir au même groupe d'e-mail partagé.
     * Renvoie un nouveau JWT pour ce profil.
     */
    #[Route('/api/me/switch-profile', methods: ['POST'])]
    public function switchProfile(Request $request): JsonResponse
    {
        /** @var User $current */
        $current = $this->getUser();
        $payload = json_decode($request->getContent(), true);
        $targetLicence = is_array($payload) ? trim((string) ($payload['num_licence'] ?? '')) : '';

        if ($targetLicence === '') {
            return new JsonResponse(['error' => 'num_licence manquant.'], Response::HTTP_BAD_REQUEST);
        }

        $accessible = $this->users->findLinkedProfiles($current);
        $target = null;
        foreach ($accessible as $u) {
            if ($u->getNumLicence() === $targetLicence) {
                $target = $u;
                break;
            }
        }

        if ($target === null) {
            return new JsonResponse(['error' => 'Profil non accessible.'], Response::HTTP_FORBIDDEN);
        }

        if (!$target->isActive()) {
            return new JsonResponse(['error' => 'Profil désactivé.'], Response::HTTP_FORBIDDEN);
        }

        $accessToken = $this->jwt->create($target);
        $refresh = $this->refreshTokenGenerator->createForUserWithTtl($target, 2592000);
        $this->refreshTokenManager->save($refresh);

        return new JsonResponse([
            'token' => $accessToken,
            'refresh_token' => $refresh->getRefreshToken(),
            'user' => AuthSuccessListener::serializeUser($target, $this->avatars->urlFor($target)),
            'linkedProfiles' => AuthSuccessListener::serializeLinkedProfiles($target, $this->users),
        ]);
    }

    #[Route('/api/me/password', methods: ['POST'])]
    public function setPassword(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $payload = json_decode($request->getContent(), true);
        $newPassword = is_array($payload) ? (string) ($payload['new_password'] ?? '') : '';

        if (mb_strlen($newPassword) < 8) {
            return new JsonResponse(['error' => 'Le mot de passe doit faire au moins 8 caractères.'], Response::HTTP_BAD_REQUEST);
        }

        $user->setPassword($this->hasher->hashPassword($user, $newPassword));
        $this->em->flush();

        return new JsonResponse(['ok' => true]);
    }

    #[Route('/api/me/devices', methods: ['POST'])]
    public function registerDevice(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return new JsonResponse(['error' => 'Corps invalide.'], Response::HTTP_BAD_REQUEST);
        }

        $token = trim((string) ($payload['expo_push_token'] ?? ''));
        $platform = (string) ($payload['platform'] ?? '');

        if ($token === '' || !str_starts_with($token, 'ExponentPushToken[')) {
            return new JsonResponse(['error' => 'expo_push_token invalide.'], Response::HTTP_BAD_REQUEST);
        }

        $platformEnum = DevicePlatform::tryFrom($platform);
        if ($platformEnum === null) {
            return new JsonResponse(['error' => 'platform doit être "ios" ou "android".'], Response::HTTP_BAD_REQUEST);
        }

        $existing = $this->deviceTokens->findOneByToken($token);
        if ($existing !== null) {
            $existing->touch();
        } else {
            $device = new DeviceToken($user, $token, $platformEnum);
            $this->em->persist($device);
        }
        $this->em->flush();

        return new JsonResponse(['ok' => true]);
    }

    #[Route('/api/me/devices/{token}', methods: ['DELETE'], requirements: ['token' => '.+'])]
    public function unregisterDevice(string $token): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $device = $this->deviceTokens->findOneByToken($token);
        if ($device !== null && $device->getUser()->getId() === $user->getId()) {
            $this->em->remove($device);
            $this->em->flush();
        }
        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * Upload (ou remplacement) de l'avatar du user courant.
     * Multipart : champ "avatar". L'image est cropée en carré 400×400
     * côté serveur ; le mobile affiche en rond.
     */
    #[Route('/api/me/avatar', methods: ['POST'])]
    public function uploadAvatar(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        /** @var UploadedFile|null $file */
        $file = $request->files->get('avatar');
        if ($file === null) {
            return new JsonResponse(['error' => 'Champ "avatar" manquant.'], Response::HTTP_BAD_REQUEST);
        }
        try {
            $this->avatars->upload($user, $file);
        } catch (\RuntimeException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        return new JsonResponse([
            'ok' => true,
            'avatarUrl' => $this->avatars->urlFor($user),
        ]);
    }

    #[Route('/api/me/avatar', methods: ['DELETE'])]
    public function deleteAvatar(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $this->avatars->remove($user);
        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
