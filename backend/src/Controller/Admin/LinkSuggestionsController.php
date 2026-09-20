<?php

namespace App\Controller\Admin;

use App\Repository\EventRepository;
use App\Repository\StaticPageRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Endpoint JSON consommé par le plugin TinyMCE « Lien interne » : liste
 * les pages statiques + événements calendrier vers lesquels un article
 * peut pointer, pour éviter à l'éditeur de coller à la main
 * `/page/<slug>` ou `/event/<id>`.
 *
 * Réservé à ROLE_EDITEUR (même palier d'accès que le CRUD articles).
 */
#[IsGranted('ROLE_EDITEUR')]
class LinkSuggestionsController extends AbstractController
{
    #[Route('/admin/link-suggestions', name: 'admin_link_suggestions', methods: ['GET'])]
    public function __invoke(
        StaticPageRepository $pages,
        EventRepository $events,
    ): JsonResponse {
        // Pages publiées uniquement — un lien vers un brouillon ne servirait
        // à rien (mobile filtre au niveau de l'API `pages.get`).
        $pageRows = [];
        foreach ($pages->findAll() as $p) {
            if (method_exists($p, 'isPublished') && !$p->isPublished()) continue;
            $pageRows[] = [
                'title' => $p->getTitle(),
                'slug' => $p->getSlug(),
                'url' => '/page/'.$p->getSlug(),
            ];
        }
        usort($pageRows, fn ($a, $b) => strcasecmp($a['title'], $b['title']));

        // Événements à venir + jusqu'à 60 jours en arrière (pour retrouver
        // un événement récent auquel se référer). Triés du plus récent au
        // plus proche à venir.
        $from = (new \DateTimeImmutable('-60 days'))->setTime(0, 0);
        $to = (new \DateTimeImmutable('+12 months'))->setTime(23, 59, 59);
        $eventRows = [];
        $qb = $events->createQueryBuilder('e')
            ->andWhere('e.startsAt BETWEEN :from AND :to')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('e.startsAt', 'DESC');
        /** @var iterable<\App\Entity\Event> $rows */
        $rows = $qb->getQuery()->getResult();
        foreach ($rows as $e) {
            $eventRows[] = [
                'title' => $e->getTitle(),
                'id' => $e->getId(),
                'url' => '/event/'.$e->getId(),
                'startsAt' => $e->getStartsAt()->format(\DATE_ATOM),
                'startsAtLabel' => $e->getStartsAt()->format('d/m/Y'),
            ];
        }

        return new JsonResponse([
            'pages' => $pageRows,
            'events' => $eventRows,
        ]);
    }
}
