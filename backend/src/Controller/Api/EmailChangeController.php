<?php

namespace App\Controller\Api;

use App\Entity\User;
use App\Message\NotifyEmailChangedMessage;
use App\Message\SendEmailChangeConfirmationMessage;
use App\Service\EmailChangeService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Changement d'adresse e-mail en libre-service.
 *
 *  - POST /api/me/email-change            (connecté) : demande → lien de
 *    confirmation envoyé à l'adresse ACTUELLE. Un compte principal change
 *    d'adresse avec ses profils rattachés ; un profil lié (adresse partagée)
 *    change seul et devient indépendant (voir EmailChangeService::confirm).
 *  - GET  /api/auth/email-change/preview  (public, jeton) : résumé affiché
 *    avant confirmation.
 *  - POST /api/auth/email-change/confirm  (public, jeton) : applique le
 *    changement. Public car le lien est ouvert depuis une boîte mail,
 *    parfois sur un autre appareil que celui de la session — le jeton
 *    (envoyé uniquement à l'adresse actuelle) fait office de preuve.
 */
class EmailChangeController extends AbstractController
{
    public function __construct(
        private readonly EmailChangeService $emailChanges,
        private readonly MessageBusInterface $bus,
    ) {
    }

    #[Route('/api/me/email-change', methods: ['POST'])]
    public function requestChange(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $payload = json_decode($request->getContent(), true);
        $newEmail = is_array($payload) ? (string) ($payload['newEmail'] ?? '') : '';

        try {
            $created = $this->emailChanges->createRequest($user, $newEmail);
        } catch (\DomainException $e) {
            return new JsonResponse(['error' => $e->getMessage()], $e->getCode() ?: Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->bus->dispatch(new SendEmailChangeConfirmationMessage(
            $created['request']->getId(),
            $created['token'],
        ));

        return new JsonResponse([
            'ok' => true,
            'sentTo' => $this->maskEmail($user->getEmail()),
        ], Response::HTTP_ACCEPTED);
    }

    #[Route('/api/auth/email-change/preview', methods: ['GET'])]
    public function preview(Request $request): JsonResponse
    {
        $pending = $this->emailChanges->findUsable((string) $request->query->get('token', ''));
        if ($pending === null) {
            return new JsonResponse(
                ['error' => 'Ce lien est invalide ou a expiré. Refaites une demande depuis votre profil.'],
                Response::HTTP_GONE,
            );
        }
        // Ce que le changement fera, à montrer avant de valider : un profil lié qui se détache de son compte principal
        // (`detachedFrom`), ou les profils rattachés qui changent d'adresse avec lui (`alsoChanges`).
        return new JsonResponse([
            'currentEmail' => $pending->getUser()->getEmail(),
            'newEmail' => $pending->getNewEmail(),
        ] + $this->emailChanges->impactOf($pending));
    }

    #[Route('/api/auth/email-change/confirm', methods: ['POST'])]
    public function confirm(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        $token = is_array($payload) ? (string) ($payload['token'] ?? '') : '';

        try {
            $result = $this->emailChanges->confirm($token);
        } catch (\DomainException $e) {
            return new JsonResponse(['error' => $e->getMessage()], $e->getCode() ?: Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->bus->dispatch(new NotifyEmailChangedMessage(
            $result['user']->getId(),
            $result['oldEmail'],
            $result['newEmail'],
            $result['detachedFrom']?->getFullName(),
            array_map(static fn (User $u) => $u->getFullName(), $result['alsoChanged']),
        ));

        return new JsonResponse([
            'ok' => true,
            'newEmail' => $result['newEmail'],
            // Nom du compte principal auquel ce profil n'est plus rattaché (null s'il n'était pas lié).
            'detachedFrom' => $result['detachedFrom']?->getFullName(),
        ]);
    }

    /** « sebastien@triathlon.com » → « se***@triathlon.com ». */
    private function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
        $visible = mb_substr($local, 0, min(2, max(1, mb_strlen($local) - 1)));
        return $visible.'***@'.$domain;
    }
}
