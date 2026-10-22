<?php

namespace App\Controller\Api;

use App\Entity\Trip;
use App\Entity\User;
use App\Repository\TripRepository;
use App\Service\Context\ContextContentSerializer;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Déplacements du club (créés depuis le backend) : chaque participant retrouve dans l'appli une vue dédiée avec
 * les informations pratiques, les événements du calendrier rattachés et les articles réservés au déplacement.
 * Un déplacement n'est jamais visible d'un adhérent qui n'y participe pas (404), ni tant qu'il est en brouillon.
 */
#[IsGranted('ROLE_USER')]
#[Route('/api/trips')]
class TripController extends AbstractController
{
    public function __construct(
        private readonly TripRepository $trips,
        private readonly ContextContentSerializer $content,
    ) {
    }

    /** Mes déplacements : à venir, en cours ou terminés depuis peu (de quoi alimenter l'accueil). */
    #[Route('/mine', methods: ['GET'])]
    public function mine(): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $this->getUser();

        return new JsonResponse([
            'data' => array_map(fn (Trip $t) => $this->summary($t), $this->trips->findCurrentForParticipant($viewer)),
        ]);
    }

    #[Route('/{id}', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function get(Trip $trip): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $this->getUser();
        if (!$trip->isPublished() || !$trip->hasParticipant($viewer)) {
            throw $this->createNotFoundException();
        }

        return new JsonResponse($this->summary($trip) + [
            'description' => $trip->getDescription(),
            // Événements existants du calendrier : ils gardent leurs filtres d'audience.
            'events' => $this->content->events($trip->getEvents(), $viewer, applyAudience: true),
            'articles' => $this->content->articles($trip->getArticles(), $viewer),
            'participants' => $this->content->participants($trip->getParticipants()),
        ]);
    }

    /** @return array<string, mixed> */
    private function summary(Trip $trip): array
    {
        return [
            'id' => $trip->getId(),
            'title' => $trip->getTitle(),
            'icon' => $trip->getIcon(),
            'location' => $trip->getLocation(),
            'startsOn' => $trip->getStartsOn()?->format('Y-m-d'),
            'endsOn' => $trip->getEndsOn()?->format('Y-m-d'),
            'participantCount' => $trip->getParticipants()->count(),
        ];
    }
}
