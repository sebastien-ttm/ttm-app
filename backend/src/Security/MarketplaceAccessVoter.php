<?php

namespace App\Security;

use App\Entity\User;
use App\Service\Marketplace\MarketplaceAccess;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Attribut `MARKETPLACE_ACCESS` (voir MarketplaceController) : ouvre
 * l'API de la bourse aux équipements aux seuls comptes autorisés par
 * MarketplaceAccess.
 */
class MarketplaceAccessVoter extends Voter
{
    public const ATTRIBUTE = 'MARKETPLACE_ACCESS';

    public function __construct(private readonly MarketplaceAccess $access)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === self::ATTRIBUTE;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        return $user instanceof User && $this->access->isEnabledFor($user);
    }
}
