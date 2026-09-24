<?php

namespace App\Controller\Api;

use App\Entity\User;
use App\Service\Marketplace\MarketplaceAccess;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Indique au mobile s'il doit afficher l'entrée « Bourse aux équipements »
 * (onglet Club). Séparé de MarketplaceController, dont toutes les routes
 * sont verrouillées par MARKETPLACE_ACCESS — celle-ci doit répondre à
 * tout utilisateur connecté, y compris pour dire « non ».
 */
#[IsGranted('ROLE_USER')]
class MarketplaceAccessController extends AbstractController
{
    public function __construct(private readonly MarketplaceAccess $access)
    {
    }

    #[Route('/api/marketplace/access', methods: ['GET'])]
    public function access(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        return new JsonResponse(['enabled' => $this->access->isEnabledFor($user)]);
    }
}
