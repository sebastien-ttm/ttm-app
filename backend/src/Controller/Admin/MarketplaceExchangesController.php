<?php

namespace App\Controller\Admin;

use App\Entity\MarketplaceConversation;
use App\Repository\MarketplaceConversationRepository;
use App\Repository\MarketplaceMessageRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Vue admin (lecture seule) des échanges entre vendeurs et acheteurs des
 * bourses aux équipements et aux dossards : une ligne par discussion, avec
 * le fil des messages en dépliant la ligne.
 *
 * Réservé aux administrateurs : ce sont des messages privés entre adhérents.
 */
#[IsGranted('ROLE_ADMIN')]
class MarketplaceExchangesController extends AbstractController
{
    use EnsureAdminContextTrait;

    /** Plafond d'affichage (les plus récentes d'abord) — la recherche permet de cibler le reste. */
    private const LIMIT = 200;

    public function __construct(
        private readonly MarketplaceConversationRepository $conversations,
        private readonly MarketplaceMessageRepository $messages,
    ) {
    }

    #[Route('/admin/bourse/echanges', name: 'admin_marketplace_exchanges', methods: ['GET'])]
    public function index(Request $request): Response
    {
        if ($r = $this->ensureAdminContext($request, 'admin_marketplace_exchanges')) {
            return $r;
        }

        $kindParam = $this->adminParam($request, 'kind');
        $kind = in_array($kindParam, ['listing', 'bib'], true) ? $kindParam : null;
        $search = trim((string) $this->adminParam($request, 'q'));

        $convs = $this->conversations->findForAdmin($kind, $search, self::LIMIT + 1);
        $truncated = count($convs) > self::LIMIT;
        if ($truncated) {
            $convs = array_slice($convs, 0, self::LIMIT);
        }
        $byConversation = $this->messages->findGroupedByConversations($convs);

        $rows = array_map(function (MarketplaceConversation $c) use ($byConversation): array {
            $messages = $byConversation[$c->getId()] ?? [];
            $sellerId = $c->getSeller()->getId();
            $sellerReplied = false;
            foreach ($messages as $m) {
                if ($m->getAuthor()->getId() === $sellerId) {
                    $sellerReplied = true;
                    break;
                }
            }
            return [
                'conversation' => $c,
                'seller' => $c->getSeller(),
                'buyer' => $c->getBuyer(),
                'messages' => $messages,
                'sellerReplied' => $sellerReplied,
            ];
        }, $convs);

        return $this->render('admin/marketplace_exchanges.html.twig', [
            'rows' => $rows,
            'kind' => $kind,
            'search' => $search,
            'truncated' => $truncated,
            'limit' => self::LIMIT,
        ]);
    }
}
