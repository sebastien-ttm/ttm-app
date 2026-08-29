<?php

namespace App\Controller\Admin;

use EasyCorp\Bundle\EasyAdminBundle\Provider\AdminContextProvider;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * Utilitaire pour les contrôleurs d'admin custom (hors CRUD EasyAdmin).
 *
 * EasyAdmin ne pose son contexte `ea` (utilisé par le layout) que pour
 * les routes portées par la classe DashboardController elle-même. Nos
 * routes custom (`/admin/pages/reorder`, `/admin/gouters`, etc.) sont
 * enregistrées comme des routes Symfony standards ; si un user y accède
 * en direct (URL tapée, bookmark), `ea` est null et le layout jette
 * une 500 « Impossible to access an attribute i18n on a null variable ».
 *
 * Le trait résout ce cas en redirigeant vers l'URL "dashboard-forwardée"
 * `/admin?routeName=…` qui, elle, passe par le subscriber EasyAdmin qui
 * pose `ea` avant de re-dispatcher vers notre contrôleur.
 */
trait EnsureAdminContextTrait
{
    private AdminContextProvider $adminContextProvider;

    /**
     * Injecté via #[Required] pour rester compatible avec un constructeur
     * hérité (les contrôleurs qui utilisent ce trait ont déjà leur propre
     * ctor avec leurs dépendances métier).
     */
    #[Required]
    public function setAdminContextProvider(AdminContextProvider $provider): void
    {
        $this->adminContextProvider = $provider;
    }

    protected function ensureAdminContext(Request $request, string $routeName): ?RedirectResponse
    {
        if ($this->adminContextProvider->getContext() !== null) {
            return null;
        }
        $params = ['routeName' => $routeName];
        $queryParams = $request->query->all();
        if ($queryParams !== []) {
            $params['routeParams'] = $queryParams;
        }
        return $this->redirect($this->generateUrl('admin_dashboard', $params));
    }
}
