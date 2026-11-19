<?php

namespace App\Controller\Api;

use App\Entity\MinibusBooking;
use App\Entity\MinibusCheck;
use App\Entity\MinibusCheckPhoto;
use App\Entity\User;
use App\Enum\MinibusCheckType;
use App\Repository\MinibusBookingRepository;
use App\Repository\MinibusSettingsRepository;
use App\Security\MinibusVoter;
use App\Service\Minibus\MinibusException;
use App\Service\Minibus\MinibusPhotoService;
use App\Service\Minibus\MinibusService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Prêt du minibus du club, dans la partie « Comité directeur » de l'espace Staff (MINIBUS : membres du CoDir pour le
 * moment). Les règles sont dans MinibusService, partagées avec le back-office.
 *  - le calendrier : les demandes en attente et validées (de tous), plus l'historique de ses propres demandes ;
 *  - une demande de réservation (dates, raison, remorque) ; elle est validée ou refusée par le gestionnaire du minibus
 *    (MINIBUS_MANAGE), qui peut y joindre un message ;
 *  - une fois la demande validée : la charte d'utilisation (modifiable dans le back-office) est présentée à l'emprunteur,
 *    qui l'accepte, puis remplit l'état des lieux de départ et celui de retour — photos, kilométrage, comment ça s'est
 *    passé, problèmes, et au retour le plein et le nettoyage ;
 *  - tous les membres du CoDir peuvent consulter les demandes et les états des lieux ; seul l'emprunteur (et le
 *    gestionnaire pour les photos) les modifie. Les photos ne sont servies qu'à ces comptes (jeton `?bearer=` pour les <img>).
 */
#[IsGranted(MinibusVoter::ACCESS)]
#[Route('/api/minibus')]
class MinibusController extends AbstractController
{
    public function __construct(
        private readonly MinibusService $service,
        private readonly MinibusPhotoService $photos,
        private readonly MinibusBookingRepository $bookings,
        private readonly MinibusSettingsRepository $settings,
        private readonly EntityManagerInterface $em,
        private readonly string $publicUrl,
    ) {
    }

    /**
     * Calendrier : demandes en attente et validées qui se terminent à partir de `from` (AAAA-MM-JJ, par défaut 90 jours
     * avant aujourd'hui), plus les demandes refusées / annulées du compte. `pendingCount` : demandes à traiter (gestionnaire).
     */
    #[Route('/bookings', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $this->getUser();
        $manager = $this->isGranted(MinibusVoter::MANAGE);
        $from = $this->parseDate((string) $request->query->get('from', ''))
            ?? (new \DateTimeImmutable('today'))->modify('-90 days');

        return $this->noStore(new JsonResponse([
            'data' => array_map(fn (MinibusBooking $b) => $this->summary($b, $viewer, $manager), $this->bookings->findForCalendar($from, $viewer)),
            'canManage' => $manager,
            'pendingCount' => $manager ? $this->bookings->countPending() : null,
        ]));
    }

    #[Route('/bookings/{id}', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function detail(int $id): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $this->getUser();

        return $this->noStore(new JsonResponse($this->detailPayload($this->findOr404($id), $viewer)));
    }

    /** Demande de réservation. JSON : { startDate, endDate, reason, withTrailer? } (dates AAAA-MM-JJ, bornes comprises). */
    #[Route('/bookings', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $this->getUser();
        $data = $this->jsonBody($request);
        $start = $this->parseDate((string) ($data['startDate'] ?? ''));
        $end = $this->parseDate((string) ($data['endDate'] ?? ''));
        if ($start === null || $end === null) {
            return $this->error('Indiquez le premier et le dernier jour (AAAA-MM-JJ).', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $booking = $this->service->request($viewer, $start, $end, (string) ($data['reason'] ?? ''), $this->bool($data['withTrailer'] ?? false));
        } catch (MinibusException $e) {
            return $this->error($e->getMessage(), $e->getStatus());
        }

        return new JsonResponse($this->detailPayload($booking, $viewer), Response::HTTP_CREATED);
    }

    /** Décision du gestionnaire. JSON : { decision: 'approve' | 'refuse', note? }. */
    #[Route('/bookings/{id}/decision', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function decide(int $id, Request $request): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $this->getUser();
        $this->denyAccessUnlessGranted(MinibusVoter::MANAGE);
        $booking = $this->findOr404($id);
        $data = $this->jsonBody($request);
        $note = isset($data['note']) ? mb_substr(trim((string) $data['note']), 0, 1000) : null;

        try {
            match ((string) ($data['decision'] ?? '')) {
                'approve' => $this->service->approve($booking, $viewer, $note),
                'refuse' => $this->service->refuse($booking, $viewer, $note),
                default => throw MinibusException::invalid('Décision inconnue : valider ou refuser.'),
            };
        } catch (MinibusException $e) {
            return $this->error($e->getMessage(), $e->getStatus());
        }

        return new JsonResponse($this->detailPayload($booking, $viewer));
    }

    /** Annulation (par l'emprunteur ou le gestionnaire) tant qu'aucun état des lieux n'est enregistré. */
    #[Route('/bookings/{id}/cancel', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function cancel(int $id): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $this->getUser();
        $booking = $this->findOr404($id);

        try {
            $this->service->cancel($booking, $viewer, $this->isGranted(MinibusVoter::MANAGE));
        } catch (MinibusException $e) {
            return $this->error($e->getMessage(), $e->getStatus());
        }

        return new JsonResponse($this->detailPayload($booking, $viewer));
    }

    /** L'emprunteur accepte la charte d'utilisation (demande validée). */
    #[Route('/bookings/{id}/charter', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function acceptCharter(int $id): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $this->getUser();
        $booking = $this->findOr404($id);

        try {
            $this->service->acceptCharter($booking, $viewer);
        } catch (MinibusException $e) {
            return $this->error($e->getMessage(), $e->getStatus());
        }

        return new JsonResponse($this->detailPayload($booking, $viewer));
    }

    /**
     * État des lieux de départ ou de retour (multipart). Champs : photos[] (fichiers JPG, PNG ou WebP), comment,
     * mileage, hasProblem, problemDescription, et au retour refueled, cleaned. Renvoyé une seconde fois, il complète
     * l'état des lieux existant (photos ajoutées, texte remplacé).
     */
    #[Route('/bookings/{id}/checks/{type}', methods: ['POST'], requirements: ['id' => '\d+', 'type' => 'departure|return'])]
    public function submitCheck(int $id, string $type, Request $request): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $this->getUser();
        $booking = $this->findOr404($id);

        $files = array_values(array_filter(
            $request->files->all('photos'),
            static fn ($f) => $f instanceof UploadedFile,
        ));
        $rawMileage = trim((string) $request->request->get('mileage', ''));
        if ($rawMileage !== '' && !ctype_digit($rawMileage)) {
            return $this->error('Kilométrage invalide : indiquez un nombre entier.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $this->service->submitCheck($booking, $viewer, MinibusCheckType::from($type), [
                'comment' => mb_substr(trim((string) $request->request->get('comment', '')), 0, 3000),
                'mileage' => $rawMileage === '' ? null : (int) $rawMileage,
                'hasProblem' => $this->bool($request->request->get('hasProblem', false)),
                'problemDescription' => mb_substr(trim((string) $request->request->get('problemDescription', '')), 0, 3000),
                'refueled' => $this->bool($request->request->get('refueled', false)),
                'cleaned' => $this->bool($request->request->get('cleaned', false)),
            ], $files);
        } catch (MinibusException $e) {
            return $this->error($e->getMessage(), $e->getStatus());
        }

        return new JsonResponse($this->detailPayload($booking, $viewer), Response::HTTP_CREATED);
    }

    /** Photo d'un état des lieux (jeton en query `bearer` pour un <img>). */
    #[Route('/photos/{id}', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function photo(int $id): Response
    {
        $photo = $this->em->find(MinibusCheckPhoto::class, $id);
        $response = $photo === null ? null : $this->photos->response($photo);
        if ($response === null) {
            throw $this->createNotFoundException();
        }

        return $response;
    }

    #[Route('/photos/{id}', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function deletePhoto(int $id): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $this->getUser();
        $photo = $this->em->find(MinibusCheckPhoto::class, $id);
        if ($photo === null) {
            return $this->error('Photo introuvable.', Response::HTTP_NOT_FOUND);
        }
        $booking = $photo->getCheck()->getBooking();

        try {
            $this->service->deletePhoto($photo, $viewer, $this->isGranted(MinibusVoter::MANAGE));
        } catch (MinibusException $e) {
            return $this->error($e->getMessage(), $e->getStatus());
        }

        return new JsonResponse($this->detailPayload($booking, $viewer));
    }

    // ============================================================
    //  Aides
    // ============================================================

    private function findOr404(int $id): MinibusBooking
    {
        $booking = $this->bookings->find($id);
        if ($booking === null) {
            throw $this->createNotFoundException();
        }

        return $booking;
    }

    /** @return array<string, mixed> */
    private function jsonBody(Request $request): array
    {
        $data = json_decode($request->getContent(), true);

        return is_array($data) ? $data : [];
    }

    private function parseDate(string $raw): ?\DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', trim($raw));

        return $date !== false && $date->format('Y-m-d') === trim($raw) ? $date : null;
    }

    private function bool(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    private function error(string $message, int $status): JsonResponse
    {
        return new JsonResponse(['error' => $message], $status);
    }

    /** Photos et décisions : jamais mises en cache par un relais. */
    private function noStore(JsonResponse $response): JsonResponse
    {
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    /** @return array<string, mixed> */
    private function summary(MinibusBooking $b, User $viewer, bool $manager): array
    {
        $mine = $b->isRequestedBy($viewer);

        return [
            'id' => $b->getId(),
            'startDate' => $b->getStartDate()->format('Y-m-d'),
            'endDate' => $b->getEndDate()->format('Y-m-d'),
            'reason' => $b->getReason(),
            'withTrailer' => $b->isWithTrailer(),
            'status' => $b->getStatus()->value,
            'statusLabel' => $b->getStatus()->label(),
            'requesterName' => $b->getRequesterName(),
            'isMine' => $mine,
            // Étape d'un emprunt validé (charter | departure | return | done) ; null si la demande n'est pas validée.
            'step' => $b->getStep(),
            'hasProblem' => $b->hasProblem(),
            // Le message du gestionnaire s'adresse à l'emprunteur (et reste lisible du gestionnaire).
            'decisionNote' => $mine || $manager ? $b->getDecisionNote() : null,
        ];
    }

    /** @return array<string, mixed> */
    private function detailPayload(MinibusBooking $b, User $viewer): array
    {
        $manager = $this->isGranted(MinibusVoter::MANAGE);
        $mine = $b->isRequestedBy($viewer);
        $settings = $this->settings->current();
        // La charte n'est présentée qu'à l'emprunteur (et au gestionnaire, qui la relit) d'une demande validée.
        $showCharter = $b->isApproved() && ($mine || $manager);
        $today = new \DateTimeImmutable('today');
        $approvedAndAccepted = $b->isApproved() && $b->isCharterAccepted();

        return $this->summary($b, $viewer, $manager) + [
            'createdAt' => $b->getCreatedAt()->format(\DATE_ATOM),
            'decidedByName' => $b->getDecidedByName(),
            'decidedAt' => $b->getDecidedAt()?->format(\DATE_ATOM),
            'charterAcceptedAt' => $b->getCharterAcceptedAt()?->format(\DATE_ATOM),
            'charter' => $showCharter ? [
                'title' => $settings->getCharterTitle(),
                'content' => $settings->getCharterContent(),
            ] : null,
            'checks' => [
                MinibusCheckType::Departure->value => $this->checkPayload($b->getCheck(MinibusCheckType::Departure)),
                MinibusCheckType::Return->value => $this->checkPayload($b->getCheck(MinibusCheckType::Return)),
            ],
            // Autres demandes (en attente ou validées) sur les mêmes jours : ce que le gestionnaire doit arbitrer.
            'conflicts' => $manager && $b->isPending()
                ? array_map(fn (MinibusBooking $c) => $this->summary($c, $viewer, $manager), $this->service->conflictsOf($b))
                : [],
            'can' => [
                'decide' => $manager && $b->isPending(),
                'cancel' => ($mine || $manager) && $b->getStatus()->isLive() && $b->getChecks()->isEmpty(),
                'acceptCharter' => $mine && $b->isApproved() && !$b->isCharterAccepted(),
                'departureCheck' => $mine && $approvedAndAccepted && $today >= $b->getStartDate()->modify('-1 day'),
                'returnCheck' => $mine && $approvedAndAccepted,
                'deletePhotos' => $manager || ($mine && $b->isApproved()),
            ],
        ];
    }

    /** @return array<string, mixed>|null */
    private function checkPayload(?MinibusCheck $check): ?array
    {
        if ($check === null) {
            return null;
        }

        return [
            'type' => $check->getType()->value,
            'typeLabel' => $check->getType()->label(),
            'submittedAt' => $check->getSubmittedAt()->format(\DATE_ATOM),
            'submittedByName' => $check->getSubmittedByName(),
            'comment' => $check->getComment(),
            'hasProblem' => $check->hasProblem(),
            'problemDescription' => $check->getProblemDescription(),
            'mileage' => $check->getMileage(),
            'refueled' => $check->isRefueled(),
            'cleaned' => $check->isCleaned(),
            'photos' => array_map(fn (MinibusCheckPhoto $p) => [
                'id' => $p->getId(),
                // URL authentifiée : le jeton est ajouté en `?bearer=` par l'appli.
                'url' => rtrim($this->publicUrl, '/').'/api/minibus/photos/'.$p->getId(),
            ], $check->getPhotos()->toArray()),
        ];
    }
}
