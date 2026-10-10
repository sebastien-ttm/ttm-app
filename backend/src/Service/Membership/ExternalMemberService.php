<?php

namespace App\Service\Membership;

use App\Entity\TrainingSeason;
use App\Entity\User;
use App\Entity\UserSeasonMembership;
use App\Repository\UserSeasonMembershipRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Activation des adhérents externes (licenciés dans un autre club) pour une saison.
 *
 * Ces comptes sont créés depuis l'appli et ne figurent jamais dans le CSV FFTri du club :
 * sans activation, l'import les désactive une fois la date limite des anciens adhérents
 * passée (voir UserRepository::findActiveNotSyncedSince). Activer = enregistrer leur adhésion
 * pour la saison (UserSeasonMembership, comme un adhérent importé) et réactiver le compte
 * s'il avait déjà été désactivé. L'activation ne vaut que pour la saison : à la suivante,
 * elle est à refaire.
 */
class ExternalMemberService
{
    public function __construct(
        private readonly UserSeasonMembershipRepository $memberships,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function isActivated(User $user, TrainingSeason $season): bool
    {
        return $this->memberships->findOneByUserAndSeason($user, $season) !== null;
    }

    /**
     * Active l'adhérent externe pour la saison. Renvoie false si rien n'a changé (déjà activé
     * et compte déjà actif). Ne fait pas de flush : c'est à l'appelant, pour grouper plusieurs
     * activations en une transaction.
     *
     * @throws \DomainException si le compte n'est pas un adhérent externe
     */
    public function activate(User $user, TrainingSeason $season): bool
    {
        if (!$user->isLicencieAutreClub()) {
            throw new \DomainException(sprintf('%s n\'est pas un adhérent externe.', $user->getFullName()));
        }

        $changed = false;
        if (!$this->isActivated($user, $season)) {
            $this->em->persist(new UserSeasonMembership($user, $season));
            $changed = true;
        }
        if (!$user->isActive()) {
            $user->setIsActive(true);
            $changed = true;
        }

        return $changed;
    }
}
