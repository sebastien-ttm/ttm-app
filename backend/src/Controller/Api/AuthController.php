<?php

namespace App\Controller\Api;

use App\Entity\LoginEvent;
use App\Entity\User;
use App\Enum\Profile;
use App\Enum\UserType;
use App\EventListener\AuthSuccessListener;
use App\Message\SendMagicLinkEmailMessage;
use App\Repository\UserRepository;
use App\Service\AvatarService;
use App\Service\LoginRecorder;
use App\Service\MagicLinkService;
use Doctrine\ORM\EntityManagerInterface;
use Gesdinet\JWTRefreshTokenBundle\Generator\RefreshTokenGeneratorInterface;
use Gesdinet\JWTRefreshTokenBundle\Model\RefreshTokenManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
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
        private readonly UserPasswordHasherInterface $hasher,
        private readonly EntityManagerInterface $em,
        private readonly AvatarService $avatars,
        private readonly LoginRecorder $loginRecorder,
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

        // Suivi de connexion (magic link) — User.lastLoginAt + LoginEvent
        $this->loginRecorder->record($user, LoginEvent::CHANNEL_MOBILE);

        // IMPORTANT : le champ "token" doit avoir la même casse/clé que la
        // réponse du login JSON Lexik, sinon les clients (mobile) ne savent
        // pas où lire le JWT et tombent en silencieux avec un "undefined"
        // stocké en localStorage.
        return new JsonResponse([
            'token' => $accessToken,
            'refresh_token' => $refresh->getRefreshToken(),
            'user' => AuthSuccessListener::serializeUser($user, $this->avatars->urlFor($user)),
            'linkedProfiles' => AuthSuccessListener::serializeLinkedProfiles($user, $this->users),
        ]);
    }

    /**
     * Inscription d'un parent non adhérent depuis le mobile.
     * Le parent doit fournir au moins UN n° de licence d'enfant adhérent
     * (anti-spam + lien réel). Auto-création : compte immédiatement actif.
     *
     * POST body attendu :
     *   { "email", "prenom", "nom", "password", "childrenLicences": [string, ...] }
     */
    #[Route('/api/auth/register-parent', methods: ['POST'])]
    public function registerParent(
        Request $request,
        RateLimiterFactory $magicLinkRequestIpLimiter,
    ): JsonResponse {
        // Rate limit basique par IP (réutilise le limiter magic-link)
        $ipLimiter = $magicLinkRequestIpLimiter->create($request->getClientIp() ?? 'unknown');
        if (!$ipLimiter->consume()->isAccepted()) {
            return new JsonResponse(['error' => 'Trop de demandes. Réessayez plus tard.'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $payload = json_decode($request->getContent() ?: '{}', true);
        if (!is_array($payload)) {
            return new JsonResponse(['error' => 'Payload JSON invalide.'], Response::HTTP_BAD_REQUEST);
        }

        $email = mb_strtolower(trim((string) ($payload['email'] ?? '')), 'UTF-8');
        $prenom = trim((string) ($payload['prenom'] ?? ''));
        $nom = trim((string) ($payload['nom'] ?? ''));
        $password = (string) ($payload['password'] ?? '');
        $childrenLicences = is_array($payload['childrenLicences'] ?? null) ? $payload['childrenLicences'] : [];

        // Validations basiques
        $errors = [];
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Email invalide.';
        }
        if ($prenom === '' || mb_strlen($prenom) > 120) {
            $errors[] = 'Prénom requis (max 120 caractères).';
        }
        if ($nom === '' || mb_strlen($nom) > 120) {
            $errors[] = 'Nom requis (max 120 caractères).';
        }
        if (mb_strlen($password) < 8) {
            $errors[] = 'Mot de passe trop court (8 caractères minimum).';
        }
        if ($childrenLicences === []) {
            $errors[] = 'Au moins un numéro de licence d\'enfant adhérent est requis.';
        }
        if ($errors !== []) {
            return new JsonResponse(['error' => 'Formulaire invalide.', 'details' => $errors], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // E-mail déjà utilisé ?
        if ($this->users->findOneByEmail($email) !== null) {
            return new JsonResponse(
                ['error' => 'Cet e-mail est déjà associé à un compte. Si vous êtes déjà adhérent, demandez à l\'administration de vous ajouter le profil Parent.'],
                Response::HTTP_CONFLICT,
            );
        }

        // Vérifier chaque licence d'enfant
        $children = [];
        $invalidLicences = [];
        foreach ($childrenLicences as $rawLicence) {
            $child = is_string($rawLicence) ? $this->users->findActiveByLicenceNormalized($rawLicence) : null;
            if ($child === null) {
                $invalidLicences[] = (string) $rawLicence;
            } else {
                $children[$child->getId()] = $child;
            }
        }
        if ($invalidLicences !== []) {
            return new JsonResponse([
                'error' => 'Numéro(s) de licence inconnu(s) ou compte inactif : '.implode(', ', $invalidLicences),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Création du compte parent
        $parent = new User();
        $parent->setEmail($email);
        $parent->setPrenom($prenom);
        $parent->setNom($nom);
        $parent->setNumLicence(null);
        $parent->setIsActive(true);
        $parent->setType(UserType::Externe);
        $parent->setSubType(User::SUBTYPE_PARENT);
        $parent->setRole('user');
        $parent->setProfiles([Profile::Parent->value]);
        $parent->setPassword($this->hasher->hashPassword($parent, $password));

        foreach ($children as $child) {
            $parent->addChild($child);
        }

        $this->em->persist($parent);
        $this->em->flush();

        // Auto-login (renvoie le même format que /api/auth/login)
        $accessToken = $this->jwt->create($parent);
        $refresh = $this->refreshTokenGenerator->createForUserWithTtl($parent, 2592000);
        $this->refreshTokenManager->save($refresh);

        // Suivi : inscription = première connexion
        $this->loginRecorder->record($parent, LoginEvent::CHANNEL_MOBILE);

        return new JsonResponse([
            'token' => $accessToken,
            'refresh_token' => $refresh->getRefreshToken(),
            'user' => AuthSuccessListener::serializeUser($parent, $this->avatars->urlFor($parent)),
            'linkedProfiles' => AuthSuccessListener::serializeLinkedProfiles($parent, $this->users),
        ], Response::HTTP_CREATED);
    }
}
