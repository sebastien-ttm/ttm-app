<?php

namespace App\Controller\Api;

use App\Entity\GouterSignup;
use App\Entity\User;
use App\Enum\Profile;
use App\Repository\GouterCancellationRepository;
use App\Repository\GouterSignupRepository;
use App\Repository\TrainingSeasonRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * API mobile pour le planning « goûter du mercredi ».
 * Accès : profils Parent OU Jeune (contrôlé au début de chaque endpoint).
 */
#[IsGranted('ROLE_USER')]
#[Route('/api/gouters')]
class GouterController extends AbstractController
{
    /** Fallback (pas de saison définie) : nombre de mercredis renvoyés. */
    private const FALLBACK_WEEKS = 12;

    public function __construct(
        private readonly GouterSignupRepository $signups,
        private readonly GouterCancellationRepository $cancellations,
        private readonly TrainingSeasonRepository $seasons,
        private readonly EntityManagerInterface $em,
    ) {
    }

    private function ensureEligible(User $user): void
    {
        if (!$user->hasProfile(Profile::Parent) && !$user->hasProfile(Profile::Jeune)) {
            throw $this->createAccessDeniedException(
                'Le planning goûter est réservé aux profils Parent et Jeune.'
            );
        }
    }

    /**
     * Retourne les mercredis de la saison courante (ou de la plage
     * demandée si ?from/?to explicites) avec les positionnements par date
     * et le drapeau isCancelled si l'admin a annulé le créneau.
     */
    #[Route('', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $this->getUser();
        $this->ensureEligible($viewer);

        $fromRaw = $request->query->get('from');
        $toRaw = $request->query->get('to');
        $from = $this->parseDate($fromRaw);
        $to = $this->parseDate($toRaw);

        // Par défaut : bornes de la saison d'entraînement courante.
        // Fallback (pas de saison / dates absentes) : 12 mercredis depuis aujourd'hui.
        if ($from === null || $to === null) {
            $season = $this->seasons->findCurrent();
            $seasonStart = $season?->getStartsAt();
            $seasonEnd = $season?->getEndsAt();
            $today = new \DateTimeImmutable('today');
            $from ??= $seasonStart ?? $today;
            $to ??= $seasonEnd ?? $today->modify('+'.self::FALLBACK_WEEKS.' weeks');
        }

        $wednesdays = $this->wednesdaysInRange($from, $to);
        if ($wednesdays === []) {
            return new JsonResponse(['slots' => []]);
        }

        $rangeStart = $wednesdays[0];
        $rangeEnd = end($wednesdays);
        $existing = $this->signups->findInRange($rangeStart, $rangeEnd);
        $cancellationMap = $this->cancellations->findMapInRange($rangeStart, $rangeEnd);

        $byDate = [];
        foreach ($existing as $s) {
            $byDate[$s->getDate()->format('Y-m-d')][] = $s;
        }

        $slots = [];
        foreach ($wednesdays as $w) {
            $key = $w->format('Y-m-d');
            $signups = $byDate[$key] ?? [];
            $cancel = $cancellationMap[$key] ?? null;
            // Le viewer est-il inscrit sur ce créneau ? Si oui, on expose
            // le numéro / WhatsApp des CO-inscrits pour permettre la mise
            // en relation (partage du binôme goûter). Sinon, aucun numéro.
            $viewerIsIn = false;
            foreach ($signups as $s) {
                if ($s->getUser()->getId() === $viewer->getId()) { $viewerIsIn = true; break; }
            }
            $slots[] = [
                'date' => $key,
                'capacity' => GouterSignup::CAPACITY_PER_SLOT,
                'isCancelled' => $cancel !== null,
                'cancellationReason' => $cancel?->getReason(),
                'signups' => array_map(function (GouterSignup $s) use ($viewer, $viewerIsIn): array {
                    $isMine = $s->getUser()->getId() === $viewer->getId();
                    $phone = $s->getUser()->getTelephone();
                    $canShare = $viewerIsIn && !$isMine && $phone !== null && $phone !== '';
                    return [
                        'id' => $s->getId(),
                        'userId' => $s->getUser()->getId(),
                        'fullName' => $s->getUser()->getFullName(),
                        'isMine' => $isMine,
                        'notes' => $s->getNotes(),
                        'createdAt' => $s->getCreatedAt()->format(\DATE_ATOM),
                        'byAdmin' => $s->getCreatedBy() !== null && $s->getCreatedBy()->getId() !== $s->getUser()->getId(),
                        'whatsappUrl' => $canShare ? self::whatsappUrlFor($phone) : null,
                    ];
                }, $signups),
            ];
        }

        return new JsonResponse(['slots' => $slots]);
    }

    /**
     * Self-signup pour une date donnée. Body : { "date": "YYYY-MM-DD" }
     */
    #[Route('', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $this->getUser();
        $this->ensureEligible($viewer);

        $payload = json_decode($request->getContent(), true);
        $dateRaw = is_array($payload) ? (string) ($payload['date'] ?? '') : '';
        $date = $this->parseDate($dateRaw);
        if ($date === null) {
            return new JsonResponse(['error' => 'Date invalide (attendu YYYY-MM-DD).'], Response::HTTP_BAD_REQUEST);
        }
        if ((int) $date->format('N') !== 3) {
            return new JsonResponse(['error' => 'La date doit être un mercredi.'], Response::HTTP_BAD_REQUEST);
        }
        if ($date < new \DateTimeImmutable('today')) {
            return new JsonResponse(['error' => 'On ne peut plus se positionner sur un mercredi passé.'], Response::HTTP_BAD_REQUEST);
        }

        if ($this->cancellations->findOneByDate($date) !== null) {
            return new JsonResponse(['error' => 'Ce mercredi a été annulé (vacances, compétition…).'], Response::HTTP_CONFLICT);
        }

        // Déjà positionné ?
        if ($this->signups->findOneByDateUser($date, $viewer) !== null) {
            return new JsonResponse(['error' => 'Vous êtes déjà positionné(e) sur ce mercredi.'], Response::HTTP_CONFLICT);
        }

        // Capacité atteinte ?
        if ($this->signups->countForDate($date) >= GouterSignup::CAPACITY_PER_SLOT) {
            return new JsonResponse(['error' => 'Créneau complet (2 personnes max).'], Response::HTTP_CONFLICT);
        }

        $signup = new GouterSignup($date, $viewer);
        $this->em->persist($signup);
        $this->em->flush();

        return new JsonResponse([
            'id' => $signup->getId(),
            'date' => $signup->getDate()->format('Y-m-d'),
        ], Response::HTTP_CREATED);
    }

    /**
     * Annule son propre positionnement.
     */
    #[Route('/{id}', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function delete(int $id): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $this->getUser();
        $this->ensureEligible($viewer);

        $signup = $this->signups->find($id);
        if ($signup === null) {
            throw $this->createNotFoundException();
        }
        if ($signup->getUser()->getId() !== $viewer->getId()) {
            throw $this->createAccessDeniedException('Vous ne pouvez annuler que vos propres positionnements.');
        }

        $this->em->remove($signup);
        $this->em->flush();

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    private function parseDate(?string $raw): ?\DateTimeImmutable
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
        return $d !== false ? $d : null;
    }

    /**
     * @return list<\DateTimeImmutable>  mercredis triés (inclus dans [from, to])
     */
    private function wednesdaysInRange(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $from = $from->setTime(0, 0, 0);
        $to = $to->setTime(0, 0, 0);
        // Premier mercredi >= $from
        $offset = (3 - (int) $from->format('N') + 7) % 7;
        $cursor = $from->modify("+$offset days");

        $result = [];
        while ($cursor <= $to) {
            $result[] = $cursor;
            $cursor = $cursor->modify('+7 days');
        }
        return $result;
    }

    /**
     * Formate un numéro français pour l'URL wa.me : retire les espaces
     * et caractères non chiffrés, remplace le préfixe 0 par 33, et strip
     * un + éventuel. Ex : « 06 12 34 56 78 » → « 33612345678 ».
     * Retourne null si le résultat n'a pas au moins 8 chiffres (numéro
     * probablement invalide).
     */
    private static function whatsappUrlFor(string $rawPhone): ?string
    {
        $digits = preg_replace('/[^\d+]/', '', $rawPhone) ?? '';
        // Numéro international déjà préfixé
        if (str_starts_with($digits, '+')) {
            $digits = substr($digits, 1);
        } elseif (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        } elseif (str_starts_with($digits, '0')) {
            // Format FR local (06XX...) → 336XX... (indicatif France).
            $digits = '33'.substr($digits, 1);
        }
        if (strlen($digits) < 8) {
            return null;
        }
        return 'https://wa.me/'.$digits;
    }
}
