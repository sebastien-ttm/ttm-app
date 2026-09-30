<?php

namespace App\Controller\Api;

use App\Entity\Event;
use App\Entity\EventCarpoolOffer;
use App\Entity\RaceProposal;
use App\Entity\User;
use App\Repository\EventCarpoolOfferRepository;
use App\Repository\EventRepository;
use App\Repository\RaceProposalRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Covoiturage sur événement DU CALENDRIER ou proposition de course :
 * chaque adhérent peut se déclarer conducteur (places + vélos,
 * éventuellement voiture pleine) ou passager (juste une demande).
 * WhatsApp est l'unique canal de mise en relation — pas de messagerie
 * interne.
 */
#[IsGranted('ROLE_USER')]
class CarpoolController extends AbstractController
{
    public function __construct(
        private readonly EventRepository $events,
        private readonly RaceProposalRepository $races,
        private readonly EventCarpoolOfferRepository $offers,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/api/events/{id}/carpool', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function listForEvent(int $id): JsonResponse
    {
        $event = $this->events->find($id);
        if ($event === null) {
            return new JsonResponse(['error' => 'Événement introuvable.'], Response::HTTP_NOT_FOUND);
        }
        if (!$event->isCarpoolingEnabled()) {
            return new JsonResponse(['error' => 'Covoiturage non activé pour cet événement.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        return new JsonResponse($this->buildBoard($event));
    }

    #[Route('/api/races/{id}/carpool', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function listForRace(int $id): JsonResponse
    {
        $race = $this->races->find($id);
        if ($race === null) {
            return new JsonResponse(['error' => 'Course introuvable.'], Response::HTTP_NOT_FOUND);
        }
        if (!$race->isCarpoolingEnabled()) {
            return new JsonResponse(['error' => 'Covoiturage non activé pour cette proposition.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        return new JsonResponse($this->buildBoard($race));
    }

    /**
     * Body : { "role": "driver"|"passenger",
     *          "seatsAvailable"?: int, "bikeSlots"?: int, "isFull"?: bool }
     *
     * seatsAvailable / bikeSlots / isFull ignorés pour un passenger.
     * Upsert : remplace la proposition existante du même user pour le
     * sujet (ou la crée si absente).
     */
    #[Route('/api/events/{id}/carpool', methods: ['POST', 'PATCH'], requirements: ['id' => '\d+'])]
    public function upsertForEvent(int $id, Request $request): JsonResponse
    {
        $event = $this->events->find($id);
        if ($event === null) {
            return new JsonResponse(['error' => 'Événement introuvable.'], Response::HTTP_NOT_FOUND);
        }
        if (!$event->isCarpoolingEnabled()) {
            return new JsonResponse(['error' => 'Covoiturage non activé pour cet événement.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        return $this->upsertFor($event, $request);
    }

    #[Route('/api/races/{id}/carpool', methods: ['POST', 'PATCH'], requirements: ['id' => '\d+'])]
    public function upsertForRace(int $id, Request $request): JsonResponse
    {
        $race = $this->races->find($id);
        if ($race === null) {
            return new JsonResponse(['error' => 'Course introuvable.'], Response::HTTP_NOT_FOUND);
        }
        if (!$race->isCarpoolingEnabled()) {
            return new JsonResponse(['error' => 'Covoiturage non activé pour cette proposition.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        return $this->upsertFor($race, $request);
    }

    #[Route('/api/events/{id}/carpool', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function deleteForEvent(int $id): JsonResponse
    {
        $event = $this->events->find($id);
        if ($event === null) {
            return new JsonResponse(['error' => 'Événement introuvable.'], Response::HTTP_NOT_FOUND);
        }
        return $this->removeFor($event);
    }

    #[Route('/api/races/{id}/carpool', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function deleteForRace(int $id): JsonResponse
    {
        $race = $this->races->find($id);
        if ($race === null) {
            return new JsonResponse(['error' => 'Course introuvable.'], Response::HTTP_NOT_FOUND);
        }
        return $this->removeFor($race);
    }

    /** @return array<string, mixed> */
    private function buildBoard(Event|RaceProposal $subject): array
    {
        /** @var User $viewer */
        $viewer = $this->getUser();
        $rows = $this->offers->findBySubject($subject);

        $drivers = [];
        $passengers = [];
        $mine = null;
        foreach ($rows as $o) {
            $entry = $this->serialize($o);
            if ($o->isDriver()) $drivers[] = $entry;
            else $passengers[] = $entry;
            if ($o->getUser()->getId() === $viewer->getId()) {
                $mine = $entry;
            }
        }

        return [
            'drivers' => $drivers,
            'passengers' => $passengers,
            'myOffer' => $mine,
        ];
    }

    private function upsertFor(Event|RaceProposal $subject, Request $request): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $this->getUser();

        $payload = json_decode($request->getContent() ?: '{}', true);
        $role = is_array($payload) ? (string) ($payload['role'] ?? '') : '';
        if (!in_array($role, EventCarpoolOffer::ROLES, true)) {
            return new JsonResponse(['error' => 'role invalide (driver|passenger).'], Response::HTTP_BAD_REQUEST);
        }

        $offer = $this->offers->findOneByUserAndSubject($viewer, $subject);
        if ($offer === null) {
            $offer = new EventCarpoolOffer($viewer, $subject, $role);
            $this->em->persist($offer);
        } else {
            $offer->setRole($role);
            $offer->touchUpdatedAt();
        }

        if ($role === EventCarpoolOffer::ROLE_DRIVER) {
            $seats = $payload['seatsAvailable'] ?? null;
            $bikes = $payload['bikeSlots'] ?? null;
            $isFull = $payload['isFull'] ?? null;
            $offer->setSeatsAvailable(is_numeric($seats) ? max(0, (int) $seats) : null);
            $offer->setBikeSlots(is_numeric($bikes) ? max(0, (int) $bikes) : null);
            $offer->setIsFull(is_bool($isFull) ? $isFull : ($isFull === '1' || $isFull === 1 || $isFull === 'true'));
        } else {
            // Nettoyage des champs conducteur si l'user bascule.
            $offer->setSeatsAvailable(null);
            $offer->setBikeSlots(null);
            $offer->setIsFull(null);
        }

        $this->em->flush();

        return new JsonResponse([
            'ok' => true,
            'offer' => $this->serialize($offer),
        ]);
    }

    private function removeFor(Event|RaceProposal $subject): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $this->getUser();
        $offer = $this->offers->findOneByUserAndSubject($viewer, $subject);
        if ($offer !== null) {
            $this->em->remove($offer);
            $this->em->flush();
        }
        return new JsonResponse(['ok' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(EventCarpoolOffer $o): array
    {
        $phone = $o->getUser()->getTelephone();
        $whatsapp = $phone !== null && $phone !== '' ? self::whatsappUrlFor($phone) : null;
        return [
            'id' => $o->getId(),
            'userId' => $o->getUser()->getId(),
            'fullName' => $o->getUser()->getFullName(),
            'role' => $o->getRole(),
            'seatsAvailable' => $o->getSeatsAvailable(),
            'bikeSlots' => $o->getBikeSlots(),
            'isFull' => $o->isFull(),
            'whatsappUrl' => $whatsapp,
            'updatedAt' => $o->getUpdatedAt()->format(\DATE_ATOM),
        ];
    }

    /** Même logique que GouterController — factorisée à terme si besoin. */
    private static function whatsappUrlFor(string $rawPhone): ?string
    {
        $digits = preg_replace('/[^\d+]/', '', $rawPhone) ?? '';
        if (str_starts_with($digits, '+')) {
            $digits = substr($digits, 1);
        } elseif (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        } elseif (str_starts_with($digits, '0')) {
            $digits = '33'.substr($digits, 1);
        }
        if (strlen($digits) < 8) return null;
        return 'https://wa.me/'.$digits;
    }
}
