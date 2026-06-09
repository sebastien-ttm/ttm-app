<?php

namespace App\Controller\Api;

use App\Entity\DeviceToken;
use App\Entity\User;
use App\Enum\DevicePlatform;
use App\Enum\Profile;
use App\EventListener\AuthSuccessListener;
use App\EventListener\JWTCreatedListener;
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
     * Liste des profils accessibles depuis le user courant. La recherche
     * est rootée sur l'« origine de session » (compte qui s'est réellement
     * connecté avec un mot de passe / magic link), pas sur le user courant
     * — pour empêcher le switch latéral vers un autre parent partageant
     * un enfant. Voir docblock de serializeLinkedProfiles().
     */
    #[Route('/api/me/linked-profiles', methods: ['GET'])]
    public function linkedProfiles(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $origin = $this->resolveOriginUser($request, $user);
        return new JsonResponse([
            'data' => AuthSuccessListener::serializeLinkedProfiles($user, $this->users, $origin),
        ]);
    }

    /**
     * Bascule vers un autre profil accessible.
     *
     * Le profil cible doit être accessible depuis l'origine de session
     * (pas seulement depuis le user courant) — sinon un parent connecté
     * pourrait, via le compte d'un enfant commun, sauter sur le compte
     * d'un autre parent.
     *
     * Accepte `user_id` (préféré, fonctionne aussi pour les comptes externes
     * sans licence) OU `num_licence` (rétrocompat).
     * Renvoie un nouveau JWT qui porte la MÊME origine de session.
     */
    #[Route('/api/me/switch-profile', methods: ['POST'])]
    public function switchProfile(Request $request): JsonResponse
    {
        /** @var User $current */
        $current = $this->getUser();
        $origin = $this->resolveOriginUser($request, $current);

        $payload = json_decode($request->getContent(), true);
        $targetId = is_array($payload) && isset($payload['user_id']) ? (int) $payload['user_id'] : 0;
        $targetLicence = is_array($payload) ? trim((string) ($payload['num_licence'] ?? '')) : '';

        if ($targetId === 0 && $targetLicence === '') {
            return new JsonResponse(['error' => 'user_id ou num_licence requis.'], Response::HTTP_BAD_REQUEST);
        }

        // ← La sécurité tient à cette ligne : on cherche le target dans
        // le réseau de l'ORIGINE, pas du current. Un Parent A connecté
        // qui est dans le profil d'un enfant commun avec Parent B ne
        // peut donc pas atteindre Parent B (qui n'est pas dans le
        // réseau de Parent A).
        $accessible = $this->users->findLinkedProfiles($origin);
        $target = null;
        foreach ($accessible as $u) {
            if ($targetId !== 0 && $u->getId() === $targetId) {
                $target = $u;
                break;
            }
            if ($targetLicence !== '' && $u->getNumLicence() === $targetLicence) {
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

        // Propage l'origine au prochain JWT — sinon le claim serait défini
        // par défaut sur target.id, et on perdrait la traçabilité.
        $originId = $origin->getId();
        if ($originId !== null) {
            $request->attributes->set(JWTCreatedListener::ORIGIN_ATTRIBUTE, $originId);
        }
        $accessToken = $this->jwt->create($target);
        $refresh = $this->refreshTokenGenerator->createForUserWithTtl($target, 2592000);
        $this->refreshTokenManager->save($refresh);

        return new JsonResponse([
            'token' => $accessToken,
            'refresh_token' => $refresh->getRefreshToken(),
            'user' => AuthSuccessListener::serializeUser($target, $this->avatars->urlFor($target)),
            'linkedProfiles' => AuthSuccessListener::serializeLinkedProfiles($target, $this->users, $origin),
        ]);
    }

    /**
     * Mise à jour des préférences de notification.
     * Body JSON : { "notifyTrainingPlanEmail": bool, ... }
     *
     * On accepte les champs un par un (PATCH-like) — seuls ceux présents
     * dans le payload sont mis à jour, les autres restent inchangés.
     */
    #[Route('/api/me/notification-preferences', methods: ['PATCH', 'POST'])]
    public function updateNotificationPreferences(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return new JsonResponse(['error' => 'Corps invalide.'], Response::HTTP_BAD_REQUEST);
        }

        if (array_key_exists('notifyTrainingPlanEmail', $payload)) {
            $user->setNotifyTrainingPlanEmail((bool) $payload['notifyTrainingPlanEmail']);
        }
        $this->em->flush();

        return new JsonResponse([
            'ok' => true,
            'notifyTrainingPlanEmail' => $user->isNotifyTrainingPlanEmail(),
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

    // ================================================================
    //   Phase E — gestion des enfants liés par le parent
    // ================================================================

    /**
     * Liste les enfants actuellement liés à ce compte.
     * Disponible à tout user authentifié — la liste est simplement
     * vide pour ceux qui n'ont pas d'enfants liés.
     */
    #[Route('/api/me/children', methods: ['GET'])]
    public function listChildren(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        return new JsonResponse([
            'data' => array_map(
                fn (User $c) => self::serializeChild($c),
                $user->getChildren()->toArray(),
            ),
            'canManage' => self::canManageChildren($user),
        ]);
    }

    /**
     * Lie un nouvel enfant adhérent à ce compte par son numéro de licence.
     *
     * Body : { "numLicence": "AB12345..." }
     *
     * Réservé aux comptes qui s'identifient comme parents (profile Parent
     * OU sub_type='parent'). Permet à un parent qui s'est inscrit sans
     * tous ses enfants — ou dont l'email diffère de celui de l'adhérent
     * — de compléter la liaison après coup.
     */
    #[Route('/api/me/children', methods: ['POST'])]
    public function addChild(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        if (!self::canManageChildren($user)) {
            return new JsonResponse(
                ['error' => 'Cette action est réservée aux comptes parents.'],
                Response::HTTP_FORBIDDEN,
            );
        }

        $payload = json_decode($request->getContent(), true);
        $raw = is_array($payload) ? trim((string) ($payload['numLicence'] ?? '')) : '';
        if ($raw === '') {
            return new JsonResponse(['error' => 'numLicence est requis.'], Response::HTTP_BAD_REQUEST);
        }

        $child = $this->users->findActiveByLicenceNormalized($raw);
        if ($child === null) {
            return new JsonResponse(
                ['error' => 'Numéro de licence inconnu ou compte inactif.'],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }
        if ($child->getId() === $user->getId()) {
            return new JsonResponse(
                ['error' => 'Vous ne pouvez pas vous lier à vous-même.'],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }
        if ($user->getChildren()->contains($child)) {
            return new JsonResponse(
                ['error' => 'Cet adhérent est déjà rattaché à votre compte.'],
                Response::HTTP_CONFLICT,
            );
        }

        $user->addChild($child);
        $this->em->flush();

        $origin = $this->resolveOriginUser($request, $user);
        return new JsonResponse([
            'ok' => true,
            'child' => self::serializeChild($child),
            // Renvoie aussi la nouvelle liste des profils accessibles, pour
            // que le mobile mette à jour son ProfileSwitcher sans aller-retour.
            // Rooté sur l'origine de session (sécurité switch latéral).
            'linkedProfiles' => AuthSuccessListener::serializeLinkedProfiles($user, $this->users, $origin),
        ], Response::HTTP_CREATED);
    }

    /**
     * Délie un enfant. Ne supprime PAS le compte enfant — juste le lien.
     */
    #[Route('/api/me/children/{id}', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function removeChild(int $id, Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        if (!self::canManageChildren($user)) {
            return new JsonResponse(
                ['error' => 'Cette action est réservée aux comptes parents.'],
                Response::HTTP_FORBIDDEN,
            );
        }

        $child = null;
        foreach ($user->getChildren() as $c) {
            if ($c->getId() === $id) {
                $child = $c;
                break;
            }
        }
        if ($child === null) {
            return new JsonResponse(
                ['error' => 'Aucun enfant lié avec cet identifiant.'],
                Response::HTTP_NOT_FOUND,
            );
        }

        $user->removeChild($child);
        $this->em->flush();

        $origin = $this->resolveOriginUser($request, $user);
        return new JsonResponse([
            'ok' => true,
            'linkedProfiles' => AuthSuccessListener::serializeLinkedProfiles($user, $this->users, $origin),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function serializeChild(User $child): array
    {
        return [
            'id' => $child->getId(),
            'fullName' => $child->getFullName(),
            'prenom' => $child->getPrenom(),
            'nom' => $child->getNom(),
            'numLicence' => $child->getNumLicence(),
            'licenceLabel' => $child->getLicenceLabel(),
            'categorieFFTri' => $child->getCategorieFFTri(),
            'profiles' => $child->getProfiles(),
            'isActive' => $child->isActive(),
        ];
    }

    /**
     * Un user est-il autorisé à gérer une liste d'enfants depuis le mobile ?
     * Critère : il s'identifie comme parent — profil Parent OU
     * (compte externe avec sub_type='parent').
     */
    private static function canManageChildren(User $user): bool
    {
        if (in_array(Profile::Parent->value, $user->getProfiles(), true)) {
            return true;
        }
        return $user->isParentExterne();
    }

    /**
     * Décode l'« origine de session » depuis le JWT courant.
     *
     * - Login initial / magic link / register : le JWT a été créé avec
     *   origin_user_id = uid (cf. JWTCreatedListener), donc l'origine
     *   est le user lui-même.
     * - Après un /switch-profile : le claim a été propagé explicitement
     *   vers le compte d'origine (jamais réécrit en route).
     * - Anciens JWT (avant Phase E+) : pas de claim → on retombe sur le
     *   user courant (= comportement historique, dégradation gracieuse
     *   jusqu'à la prochaine reconnexion).
     */
    private function resolveOriginUser(Request $request, User $current): User
    {
        $auth = (string) $request->headers->get('Authorization', '');
        if (!str_starts_with($auth, 'Bearer ')) {
            return $current;
        }
        try {
            $payload = $this->jwt->parse(substr($auth, 7));
        } catch (\Throwable) {
            return $current;
        }
        $originId = $payload['origin_user_id'] ?? null;
        if (!is_int($originId) || $originId <= 0 || $originId === $current->getId()) {
            return $current;
        }
        $origin = $this->users->find($originId);
        if ($origin === null || !$origin->isActive()) {
            // L'origine a été désactivée / supprimée : on garde quand même
            // une session navigable en retombant sur le user courant. Le
            // switcher ne montrera que ses voisins immédiats — pas idéal
            // mais on évite de bloquer un user piégé.
            return $current;
        }
        return $origin;
    }
}
