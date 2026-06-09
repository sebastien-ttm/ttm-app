<?php

namespace App\EventListener;

use App\Entity\LoginEvent;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\AvatarService;
use App\Service\LoginRecorder;
use Doctrine\ORM\EntityManagerInterface;
use Gesdinet\JWTRefreshTokenBundle\Generator\RefreshTokenGeneratorInterface;
use Gesdinet\JWTRefreshTokenBundle\Model\RefreshTokenManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Event\AuthenticationSuccessEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

class AuthSuccessListener
{
    public function __construct(
        private readonly RefreshTokenGeneratorInterface $refreshTokenGenerator,
        private readonly RefreshTokenManagerInterface $refreshTokenManager,
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $em,
        private readonly AvatarService $avatars,
        private readonly LoginRecorder $loginRecorder,
        private readonly int $refreshTtl = 2592000,
    ) {
    }

    #[AsEventListener(event: 'lexik_jwt_authentication.on_authentication_success')]
    public function onAuthenticationSuccess(AuthenticationSuccessEvent $event): void
    {
        $user = $event->getUser();
        if (!$user instanceof User) {
            return;
        }

        $data = $event->getData();

        $refreshToken = $this->refreshTokenGenerator->createForUserWithTtl($user, $this->refreshTtl);
        $this->refreshTokenManager->save($refreshToken);

        $data['refresh_token'] = $refreshToken->getRefreshToken();
        $data['user'] = self::serializeUser($user, $this->avatars->urlFor($user));
        $data['linkedProfiles'] = self::serializeLinkedProfiles($user, $this->users);
        $event->setData($data);

        // Suivi de connexion (mobile) — met à jour lastLoginAt + loginCount
        // ET persiste un LoginEvent pour les stats fines.
        $this->loginRecorder->record($user, LoginEvent::CHANNEL_MOBILE);
    }

    /**
     * @return array<string, mixed>
     */
    public static function serializeUser(User $user, ?string $avatarUrl = null): array
    {
        return [
            'id' => $user->getId(),
            'email' => $user->getEmail(),
            'nom' => $user->getNom(),
            'prenom' => $user->getPrenom(),
            'fullName' => $user->getFullName(),
            'numLicence' => $user->getNumLicence(),
            'licenceLabel' => $user->getLicenceLabel(),
            'type' => $user->getType()->value,
            'subType' => $user->getSubType(),
            'profiles' => $user->getProfiles(),
            'role' => $user->getRole(),
            'isDirigeant' => $user->isDirigeant(),
            'categorieFFTri' => $user->getCategorieFFTri(),
            // Conservé pour rétrocompat mobile : "jeune" / "senior" / null
            'categorie' => $user->isJeune() ? 'jeune' : ($user->isSenior() ? 'senior' : null),
            'roles' => $user->getRoles(),
            'hasPassword' => $user->getPassword() !== null,
            'avatarUrl' => $avatarUrl,
            'notifyTrainingPlanEmail' => $user->isNotifyTrainingPlanEmail(),
        ];
    }

    /**
     * Renvoie la liste de tous les profils accessibles depuis ce user
     * (lui-même + ses dépendants, OU son primaire + tous les dépendants
     * du primaire).
     *
     * Le réseau est calculé depuis $origin si fourni — sinon depuis $user.
     * Cette indirection permet de bloquer le « switch latéral » : quand
     * Parent A est connecté puis switché dans le profil d'un enfant
     * commun avec Parent B, on calcule les profils accessibles depuis
     * Parent A (l'origine de session) et non depuis l'enfant. Parent B
     * n'apparaît donc pas dans le switcher.
     *
     * @return list<array<string, mixed>>
     */
    public static function serializeLinkedProfiles(User $user, UserRepository $users, ?User $origin = null): array
    {
        $rootUser = $origin ?? $user;
        $profiles = $users->findLinkedProfiles($rootUser);
        if (count($profiles) < 2) {
            return []; // pas la peine d'envoyer une liste s'il n'y a qu'un seul profil
        }
        return array_map(fn (User $u) => [
            'id' => $u->getId(),
            'numLicence' => $u->getNumLicence(),
            'fullName' => $u->getFullName(),
            'prenom' => $u->getPrenom(),
            'type' => $u->getType()->value,
            'profiles' => $u->getProfiles(),
            // Conservé pour rétrocompat mobile
            'categorie' => $u->isJeune() ? 'jeune' : ($u->isSenior() ? 'senior' : null),
            'categorieAge' => $u->getCategorieAge(),
            'isPrimary' => $u->isPrimary(),
            'isCurrent' => $u->getId() === $user->getId(),
        ], $profiles);
    }
}
