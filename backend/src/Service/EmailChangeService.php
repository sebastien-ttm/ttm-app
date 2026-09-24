<?php

namespace App\Service;

use App\Entity\EmailChangeRequest;
use App\Entity\User;
use App\Repository\EmailChangeRequestRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Changement d'adresse e-mail en libre-service, en 2 temps :
 *  1. createRequest() : l'user (connecté) demande une nouvelle adresse ;
 *     un jeton est émis, envoyé par e-mail à l'adresse ACTUELLE.
 *  2. confirm() : le clic sur le lien reçu dans la boîte actuelle
 *     applique réellement le changement.
 *
 * Les erreurs métier remontent en \DomainException dont le code porte le
 * statut HTTP à renvoyer (422 par défaut, 429 cooldown, 409 conflit,
 * 410 lien invalide/expiré).
 */
class EmailChangeService
{
    private const TTL_SECONDS = 7200;
    private const COOLDOWN_SECONDS = 60;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EmailChangeRequestRepository $requests,
        private readonly UserRepository $users,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{request: EmailChangeRequest, token: string}
     *
     * @throws \DomainException
     */
    public function createRequest(User $user, string $rawNewEmail): array
    {
        $newEmail = mb_strtolower(trim($rawNewEmail), 'UTF-8');
        if ($newEmail === '' || mb_strlen($newEmail) > 180 || !filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
            throw new \DomainException('Adresse e-mail invalide.', 422);
        }
        $this->assertEligible($user);
        if ($newEmail === mb_strtolower($user->getEmail(), 'UTF-8')) {
            throw new \DomainException('C\'est déjà votre adresse e-mail actuelle.', 422);
        }
        if ($this->isTaken($newEmail, $user)) {
            throw new \DomainException(
                'Cette adresse e-mail est déjà utilisée par un autre compte. '
                .'Pour rattacher des profils à une même adresse, contactez le club.',
                409,
            );
        }

        $latest = $this->requests->findLatestForUser($user);
        if ($latest !== null && $latest->getCreatedAt() > new \DateTimeImmutable('-'.self::COOLDOWN_SECONDS.' seconds')) {
            throw new \DomainException('Une demande vient d\'être envoyée. Patientez une minute avant de réessayer.', 429);
        }

        // Une seule demande active à la fois : la nouvelle annule les précédentes.
        foreach ($this->requests->findPendingForUser($user) as $pending) {
            $pending->markUsed();
        }

        $clear = bin2hex(random_bytes(32));
        $request = new EmailChangeRequest(
            $user,
            $newEmail,
            hash('sha256', $clear),
            new \DateTimeImmutable('+'.self::TTL_SECONDS.' seconds'),
        );
        $this->em->persist($request);
        $this->em->flush();

        return ['request' => $request, 'token' => $clear];
    }

    /**
     * Demande valide pour ce jeton, sans la consommer (écran de
     * confirmation côté mobile). Null si inconnu, déjà utilisé ou expiré.
     */
    public function findUsable(string $clearToken): ?EmailChangeRequest
    {
        if ($clearToken === '') {
            return null;
        }
        $request = $this->requests->findOneByTokenHash(hash('sha256', $clearToken));
        return $request !== null && $request->isUsable() ? $request : null;
    }

    /**
     * Applique le changement. Le compte et ses dépendants qui partagent
     * son adresse (famille rattachée) basculent ensemble — sinon la
     * famille se retrouverait éclatée sur deux boîtes.
     *
     * @return array{user: User, oldEmail: string, newEmail: string}
     *
     * @throws \DomainException
     */
    public function confirm(string $clearToken): array
    {
        $request = $this->findUsable($clearToken);
        if ($request === null) {
            throw new \DomainException('Ce lien est invalide ou a expiré. Refaites une demande depuis votre profil.', 410);
        }

        $user = $request->getUser();
        $newEmail = $request->getNewEmail();
        $this->assertEligible($user);
        // Re-vérification : l'adresse a pu être prise entre la demande et le clic.
        if ($this->isTaken($newEmail, $user)) {
            throw new \DomainException('Cette adresse e-mail est désormais utilisée par un autre compte.', 409);
        }

        $oldEmail = mb_strtolower($user->getEmail(), 'UTF-8');
        $user->setEmail($newEmail);
        foreach ($user->getDependents() as $dependent) {
            if (mb_strtolower($dependent->getEmail(), 'UTF-8') === $oldEmail) {
                $dependent->setEmail($newEmail);
            }
        }
        $request->markUsed();
        foreach ($this->requests->findPendingForUser($user) as $other) {
            $other->markUsed();
        }
        $this->em->flush();

        $this->carryRefreshTokens($oldEmail, $newEmail);

        $this->logger->info('Changement d\'e-mail confirmé', [
            'userId' => $user->getId(),
            'from' => $oldEmail,
            'to' => $newEmail,
        ]);

        return ['user' => $user, 'oldEmail' => $oldEmail, 'newEmail' => $newEmail];
    }

    /**
     * Seuls les comptes PRIMAIRES portent l'identité de connexion : un
     * profil dépendant partage l'adresse (et la connexion) de son
     * primaire, qui doit changer pour toute la famille.
     */
    private function assertEligible(User $user): void
    {
        $primary = $user->getLinkedToUser();
        if ($primary !== null) {
            throw new \DomainException(
                sprintf(
                    'Ce profil partage l\'adresse e-mail de %s. Le changement se fait depuis le compte principal.',
                    $primary->getFullName(),
                ),
                409,
            );
        }
    }

    private function isTaken(string $email, User $user): bool
    {
        $count = (int) $this->users->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->where('LOWER(u.email) = :email')
            ->andWhere('u.id != :id')
            ->setParameter('email', $email)
            ->setParameter('id', $user->getId())
            ->getQuery()
            ->getSingleScalarResult();
        return $count > 0;
    }

    /**
     * Les refresh tokens mémorisent l'e-mail comme identifiant : sans ce
     * report, les sessions ouvertes (tous appareils) casseraient au
     * prochain renouvellement du JWT. On ne migre que si plus personne
     * n'utilise l'ancienne adresse — sinon impossible de savoir à qui
     * appartient chaque token, on laisse les sessions expirer d'elles-mêmes.
     */
    private function carryRefreshTokens(string $oldEmail, string $newEmail): void
    {
        $stillUsed = (int) $this->users->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->where('LOWER(u.email) = :email')
            ->setParameter('email', $oldEmail)
            ->getQuery()
            ->getSingleScalarResult();
        if ($stillUsed > 0) {
            return;
        }
        $this->em->getConnection()->executeStatement(
            'UPDATE refresh_token SET username = :new WHERE LOWER(username) = :old',
            ['new' => $newEmail, 'old' => $oldEmail],
        );
    }
}
