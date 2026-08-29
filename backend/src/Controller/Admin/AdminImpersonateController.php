<?php

namespace App\Controller\Admin;

use App\Repository\UserRepository;
use App\Service\MagicLinkService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Admin → mobile : impersonation d'un adhérent via magic link à usage
 * unique. Court-circuite le formulaire de login classique — pratique pour
 * reproduire un bug rapporté par un utilisateur, tester ses droits, etc.
 *
 * Sécurité : ROLE_ADMIN requis. On génère un magic link comme d'habitude
 * (usage unique, TTL court côté MagicLinkService). Le flag ?impersonate=1
 * dans l'URL est capté par le mobile pour afficher un bandeau permanent
 * « connecté en tant que X ».
 */
#[IsGranted('ROLE_ADMIN')]
class AdminImpersonateController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly MagicLinkService $magicLinks,
    ) {
    }

    #[Route('/admin/impersonate/{id}', name: 'admin_impersonate', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function impersonate(int $id): RedirectResponse
    {
        $target = $this->users->find($id);
        if ($target === null) {
            throw $this->createNotFoundException();
        }
        if (!$target->isActive()) {
            throw $this->createAccessDeniedException('Compte cible désactivé.');
        }
        $issued = $this->magicLinks->issue($target);
        $url = $this->magicLinks->buildWebUrl($issued['token']).'&impersonate=1';
        return new RedirectResponse($url);
    }
}
