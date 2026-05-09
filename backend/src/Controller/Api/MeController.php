<?php

namespace App\Controller\Api;

use App\Entity\DeviceToken;
use App\Entity\User;
use App\Enum\DevicePlatform;
use App\EventListener\AuthSuccessListener;
use App\Repository\DeviceTokenRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
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
        private readonly UserPasswordHasherInterface $hasher,
    ) {
    }

    #[Route('/api/me', methods: ['GET'])]
    public function me(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        return new JsonResponse(AuthSuccessListener::serializeUser($user));
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
}
