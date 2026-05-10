<?php

namespace App\EventListener;

use App\Entity\User;
use App\Repository\CharterAcceptanceRepository;
use App\Repository\ClubCharterRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Forces every authenticated admin (ROLE_COACH+) to accept the current
 * charter before they can use any admin page, except for a small allowlist
 * of paths required for the bootstrap (login, logout, charter management,
 * the acceptance page itself).
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 7)]
class AdminCharterEnforcer
{
    /**
     * Path prefixes that DO NOT require charter acceptance.
     * Note: the Charter CRUD is allowed so an admin can publish a new
     * charter even before having accepted it themselves.
     */
    private const ALLOWED_PREFIXES = [
        '/admin/login',
        '/admin/logout',
        '/admin/charter/accept',
        // EasyAdmin's CRUD URL for ClubCharter has the form
        // `/admin?crudControllerFqcn=...ClubCharterCrudController`. The
        // base /admin path is used for the dashboard so we can't fully
        // allow it. Detection is done via query string below.
    ];

    public function __construct(
        private readonly Security $security,
        private readonly ClubCharterRepository $charters,
        private readonly CharterAcceptanceRepository $acceptances,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $path = $request->getPathInfo();

        // Only enforce on /admin (not the API)
        if (!str_starts_with($path, '/admin')) {
            return;
        }

        // Bootstrap allowlist
        foreach (self::ALLOWED_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return;
            }
        }

        // Allow the EasyAdmin Charter CRUD so admins can manage charters
        // even before having accepted the current one.
        $crudFqcn = $request->query->get('crudControllerFqcn', '');
        if (is_string($crudFqcn) && str_contains($crudFqcn, 'ClubCharterCrudController')) {
            return;
        }

        // Also allow the tracking page (read-only)
        if ($path === '/admin/charter/tracking') {
            return;
        }

        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return; // Not authenticated yet → security firewall handles redirect
        }

        $charter = $this->charters->findCurrent();
        if ($charter === null) {
            return; // No active charter → nothing to enforce
        }

        if ($this->acceptances->hasAccepted($user, $charter)) {
            return;
        }

        $event->setResponse(new RedirectResponse(
            $this->urls->generate('admin_charter_accept'),
        ));
    }
}
