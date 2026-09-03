<?php

namespace App\Security;

use App\Entity\User;
use App\Repository\UserRepository;
use Lexik\Bundle\JWTAuthenticationBundle\Security\User\PayloadAwareUserProviderInterface;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * UserProvider Symfony pour le firewall API (Lexik JWT) + le firewall
 * admin (form_login classique).
 *
 * Deux comptes peuvent partager un email (parent + enfants dépendants
 * dans la table `user`). Résoudre le user à partir de l'email seul est
 * donc ambigu — on garantit le bon compte en lisant le claim `uid` du
 * JWT dès la phase d'auth Lexik, avant même que le TokenStorage ne
 * soit peuplé.
 *
 *  - loadUserByIdentifier($email) : chargement classique (form_login
 *    admin, refresh sans payload) → retourne le PRIMAIRE de l'email
 *    (linkedToUser IS NULL). Bloque tout usage d'un email pour se
 *    connecter en tant que compte dépendant.
 *
 *  - loadUserByIdentifierAndPayload($email, $payload) : Lexik JWT.
 *    Si le payload contient `uid`, load par ID directement (garantit
 *    le compte exact identifié par le JWT — parent OU dépendant).
 *    Sinon fallback sur loadUserByIdentifier.
 */
class JwtUidAwareUserProvider implements UserProviderInterface, PayloadAwareUserProviderInterface, PasswordUpgraderInterface
{
    public function __construct(
        private readonly UserRepository $users,
    ) {
    }

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        $user = $this->users->findOneByEmail($identifier);
        if (!$user instanceof User) {
            $ex = new UserNotFoundException(\sprintf('Aucun compte primaire pour "%s".', $identifier));
            $ex->setUserIdentifier($identifier);
            throw $ex;
        }
        return $user;
    }

    public function loadUserByIdentifierAndPayload(string $identity, array $payload): UserInterface
    {
        // Priorité au claim `uid` (posé par JWTCreatedListener) — c'est
        // l'identifiant strict de l'utilisateur pour lequel le JWT a été
        // émis. Défensif : tolère string ou int.
        $uid = $payload['uid'] ?? null;
        if (is_int($uid) && $uid > 0) {
            $found = $this->users->find($uid);
            if ($found instanceof User) {
                return $found;
            }
        } elseif (is_string($uid) && ctype_digit($uid) && (int) $uid > 0) {
            $found = $this->users->find((int) $uid);
            if ($found instanceof User) {
                return $found;
            }
        }
        // JWT sans uid (anciens tokens émis avant le claim, ou payload
        // altéré) : fallback sur le chargement par email.
        return $this->loadUserByIdentifier($identity);
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(\sprintf('Instances of "%s" are not supported.', $user::class));
        }
        $id = $user->getId();
        if ($id === null) {
            return $user;
        }
        $fresh = $this->users->find($id);
        if (!$fresh instanceof User) {
            throw new UserNotFoundException(\sprintf('User #%d introuvable après refresh.', $id));
        }
        return $fresh;
    }

    public function supportsClass(string $class): bool
    {
        return $class === User::class || is_subclass_of($class, User::class);
    }

    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof User) {
            return;
        }
        // Délègue au repository qui contient déjà la logique de flush.
        $this->users->upgradePassword($user, $newHashedPassword);
    }
}
