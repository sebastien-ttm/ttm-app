<?php

namespace App\Service;

use App\Entity\PasswordResetToken;
use App\Entity\RefreshToken;
use App\Entity\User;
use App\Repository\PasswordResetTokenRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * « Mot de passe oublié » : émission du lien envoyé par e-mail, vérification du jeton et
 * enregistrement du nouveau mot de passe.
 *
 * Sécurité : jeton aléatoire de 256 bits, stocké haché (SHA-256) ; valable une heure et à
 * usage unique ; une nouvelle demande invalide les liens précédents ; une fois le mot de
 * passe changé, les connexions ouvertes (jetons de rafraîchissement) sont fermées.
 */
class PasswordResetService
{
    public const TTL_SECONDS = 3600;
    public const MIN_PASSWORD_LENGTH = 8;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PasswordResetTokenRepository $tokens,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly string $publicUrl,
    ) {
    }

    /** Crée un lien de réinitialisation et renvoie le jeton EN CLAIR (à n'envoyer que par e-mail). */
    public function issue(User $user): string
    {
        $this->tokens->invalidateUnusedForUser($user);

        $clear = bin2hex(random_bytes(32));
        $this->em->persist(new PasswordResetToken(
            $user,
            hash('sha256', $clear),
            new \DateTimeImmutable('+'.self::TTL_SECONDS.' seconds'),
        ));
        $this->em->flush();

        return $clear;
    }

    /** Adresse de la page de l'appli où l'adhérent choisit son nouveau mot de passe. */
    public function buildUrl(string $clearToken): string
    {
        return rtrim($this->publicUrl, '/').'/reset-password?token='.urlencode($clearToken);
    }

    /** Le jeton correspondant au lien reçu, s'il est encore utilisable (non utilisé, non expiré). */
    public function findUsable(string $clearToken): ?PasswordResetToken
    {
        if ($clearToken === '') {
            return null;
        }
        $token = $this->tokens->findOneByTokenHash(hash('sha256', $clearToken));

        return $token !== null && $token->isUsable() ? $token : null;
    }

    /** Enregistre le nouveau mot de passe, consomme le jeton et ferme les connexions ouvertes. */
    public function complete(PasswordResetToken $token, string $newPassword): User
    {
        $user = $token->getUser();
        $user->setPassword($this->hasher->hashPassword($user, $newPassword));
        $token->markUsed();

        // Les autres liens en attente ne doivent plus fonctionner.
        $this->tokens->invalidateUnusedForUser($user);
        // Le mot de passe vient de changer : on ferme les sessions déjà ouvertes (si quelqu'un d'autre
        // était connecté avec l'ancien). L'appelant ouvre ensuite une nouvelle session pour l'adhérent.
        $this->em->createQuery('DELETE FROM '.RefreshToken::class.' r WHERE r.username = :username')
            ->setParameter('username', $user->getUserIdentifier())
            ->execute();

        $this->em->flush();

        return $user;
    }
}
