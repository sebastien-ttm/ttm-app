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
        $next = is_array($payload) ? trim((string) ($payload['next'] ?? '')) : '';
        $next = $this->sanitizeNext($next);

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
                next: $next,
            ));
        }

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * Filtre un paramètre `next` fourni par le client pour un magic link :
     * - doit commencer par un unique `/` (URL relative interne, pas d'origin)
     * - refuse les routes d'auth pour éviter les boucles ou fuites de token
     * - refuse les schemes/protocoles absolus (redirection ouverte)
     * - garde-fou de longueur pour l'URL email (2048 chars typiques)
     */
    private function sanitizeNext(string $raw): ?string
    {
        if ($raw === '' || strlen($raw) > 512) {
            return null;
        }
        if ($raw[0] !== '/' || (isset($raw[1]) && $raw[1] === '/')) {
            return null; // pas d'URL absolue "//evil.com/..." ou "http://..."
        }
        // Refuse les schemes cachés (curieux, mais possible avec des caractères
        // encodés — on filtre par sécurité)
        if (preg_match('#^[a-z][a-z0-9+.\-]*:#i', ltrim($raw, '/'))) {
            return null;
        }
        // Refuse les paths d'auth (évite boucle magic-link → magic-link, etc.)
        if (str_starts_with($raw, '/auth/') || str_starts_with($raw, '/(auth)')) {
            return null;
        }
        return $raw;
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

    /**
     * Inscription d'un adhérent EXTERNE : adhérent du club dont la
     * licence FFTri est prise dans un autre club (type=Adherent,
     * subType=AutreClub). Même flow que register-parent, mais on
     * demande la date de naissance (pour dériver le profil principal
     * Jeune/Sénior, comme à l'import CSV FFTri) et le numéro de licence
     * (celui de l'autre club).
     *
     * POST body attendu :
     *   { "email", "prenom", "nom", "password", "dateNaissance": "YYYY-MM-DD",
     *     "numLicence": string }
     */
    #[Route('/api/auth/register-member', methods: ['POST'])]
    public function registerMember(
        Request $request,
        RateLimiterFactory $magicLinkRequestIpLimiter,
    ): JsonResponse {
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
        $dateNaissanceRaw = trim((string) ($payload['dateNaissance'] ?? ''));
        $numLicenceRaw = trim((string) ($payload['numLicence'] ?? ''));

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
        $dateNaissance = null;
        if ($dateNaissanceRaw !== '') {
            $dateNaissance = \DateTimeImmutable::createFromFormat('!Y-m-d', $dateNaissanceRaw)
                ?: \DateTimeImmutable::createFromFormat('!d/m/Y', $dateNaissanceRaw)
                ?: null;
        }
        if ($dateNaissance === null) {
            $errors[] = 'Date de naissance requise au format AAAA-MM-JJ ou JJ/MM/AAAA.';
        } elseif ($dateNaissance > new \DateTimeImmutable('today')) {
            $errors[] = 'Date de naissance dans le futur — vérifiez la saisie.';
        }
        $numLicence = null;
        if ($numLicenceRaw === '') {
            $errors[] = 'Numéro de licence requis.';
        } else {
            $numLicence = User::normalizeLicence($numLicenceRaw);
            if ($numLicence === null) {
                $errors[] = 'Numéro de licence invalide.';
            }
        }
        if ($errors !== []) {
            return new JsonResponse(['error' => 'Formulaire invalide.', 'details' => $errors], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Unicité du n° de licence (index déjà en base sur num_licence — on
        // renvoie une erreur claire plutôt qu'une SQLException).
        if ($this->users->findOneByNumLicence($numLicence) !== null) {
            return new JsonResponse(
                ['error' => 'Ce numéro de licence est déjà associé à un compte.'],
                Response::HTTP_CONFLICT,
            );
        }

        if ($this->users->findOneByEmail($email) !== null) {
            return new JsonResponse(
                ['error' => 'Cet e-mail est déjà associé à un compte.'],
                Response::HTTP_CONFLICT,
            );
        }

        $principalProfile = Profile::principalFromBirthDate($dateNaissance);

        $member = new User();
        $member->setEmail($email);
        $member->setPrenom($prenom);
        $member->setNom($nom);
        $member->setDateNaissance($dateNaissance);
        $member->setNumLicence($numLicence);
        $member->setIsActive(true);
        // Adhérent du club licencié dans un autre club (typiquement
        // membres du bureau, bénévoles, sympathisants qui prennent leur
        // licence FFTri ailleurs). L'admin peut basculer sur SUBTYPE_CLUB
        // ultérieurement s'ils prennent leur licence à TTM.
        $member->setType(UserType::Adherent);
        $member->setSubType(User::SUBTYPE_AUTRE_CLUB);
        $member->setRole('user');
        $member->setProfiles([$principalProfile->value]);
        $member->setPassword($this->hasher->hashPassword($member, $password));

        $this->em->persist($member);
        $this->em->flush();

        $accessToken = $this->jwt->create($member);
        $refresh = $this->refreshTokenGenerator->createForUserWithTtl($member, 2592000);
        $this->refreshTokenManager->save($refresh);

        $this->loginRecorder->record($member, LoginEvent::CHANNEL_MOBILE);

        return new JsonResponse([
            'token' => $accessToken,
            'refresh_token' => $refresh->getRefreshToken(),
            'user' => AuthSuccessListener::serializeUser($member, $this->avatars->urlFor($member)),
            'linkedProfiles' => AuthSuccessListener::serializeLinkedProfiles($member, $this->users),
        ], Response::HTTP_CREATED);
    }
}
