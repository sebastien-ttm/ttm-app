<?php

namespace App\Service\Minibus;

use App\Entity\MinibusBooking;
use App\Entity\MinibusCheck;
use App\Entity\MinibusCheckPhoto;
use App\Entity\User;
use App\Enum\MinibusBookingStatus;
use App\Enum\MinibusCheckType;
use App\Repository\MinibusBookingRepository;
use App\Repository\MinibusSettingsRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Règles du prêt du minibus du club, partagées par l'API de l'appli et le back-office :
 *  - une demande couvre des jours entiers ; elle est refusée si le minibus est déjà promis (demande validée) sur ces jours.
 *    Plusieurs demandes en attente peuvent se chevaucher : c'est le gestionnaire qui arbitre ;
 *  - le gestionnaire valide ou refuse une demande en attente (une validation re-vérifie que le créneau est libre) ;
 *  - l'emprunteur annule tant qu'aucun état des lieux n'est enregistré ; une fois la demande validée il accepte la charte,
 *    puis remplit l'état des lieux de départ (à partir de la veille) et celui de retour — photos, compte rendu, problèmes.
 *
 * Toute règle non respectée lève une MinibusException au message montrable à l'utilisateur.
 */
class MinibusService
{
    /** Durée maximale d'une demande (jours, bornes comprises). */
    public const MAX_DAYS = 31;
    public const MAX_MILEAGE = 2_000_000;
    public const MAX_REASON = 1000;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MinibusBookingRepository $bookings,
        private readonly MinibusSettingsRepository $settings,
        private readonly MinibusPhotoService $photos,
    ) {
    }

    /**
     * Enregistre une demande (en attente de la décision du gestionnaire).
     *
     * @throws MinibusException
     */
    public function request(User $requester, \DateTimeImmutable $start, \DateTimeImmutable $end, string $reason, bool $withTrailer): MinibusBooking
    {
        $start = $start->setTime(0, 0);
        $end = $end->setTime(0, 0);
        if ($start < new \DateTimeImmutable('today')) {
            throw MinibusException::invalid('Le premier jour ne peut pas être dans le passé.');
        }
        if ($end < $start) {
            throw MinibusException::invalid('Le dernier jour ne peut pas précéder le premier.');
        }
        if ((int) $start->diff($end)->days + 1 > self::MAX_DAYS) {
            throw MinibusException::invalid(sprintf('Une demande couvre %d jours au plus.', self::MAX_DAYS));
        }
        $reason = trim($reason);
        if (mb_strlen($reason) < 3) {
            throw MinibusException::invalid('Indiquez la raison de l\'emprunt.');
        }
        if (mb_strlen($reason) > self::MAX_REASON) {
            throw MinibusException::invalid(sprintf('La raison est trop longue (%d caractères au plus).', self::MAX_REASON));
        }
        $this->assertFree($start, $end, null);

        $booking = new MinibusBooking($requester, $start, $end, $reason, $withTrailer);
        $this->em->persist($booking);
        $this->em->flush();

        return $booking;
    }

    /** @throws MinibusException */
    public function approve(MinibusBooking $booking, User $manager, ?string $note): void
    {
        $this->requirePending($booking);
        // Une autre demande a pu être validée entre-temps sur les mêmes jours.
        $this->assertFree($booking->getStartDate(), $booking->getEndDate(), $booking);
        $booking->setDecisionNote($note);
        $booking->approve($manager);
        $this->em->flush();
    }

    /** @throws MinibusException */
    public function refuse(MinibusBooking $booking, User $manager, ?string $note): void
    {
        $this->requirePending($booking);
        $booking->refuse($manager, $note);
        $this->em->flush();
    }

    /**
     * Annule une demande en attente ou validée. L'emprunteur le fait lui-même ; le gestionnaire peut aussi annuler
     * (imprévu, véhicule indisponible). Plus possible dès qu'un état des lieux est enregistré : l'emprunt a eu lieu.
     *
     * @throws MinibusException
     */
    public function cancel(MinibusBooking $booking, User $by, bool $isManager): void
    {
        if (!$isManager) {
            $this->requireBorrower($booking, $by);
        }
        if (!$booking->getStatus()->isLive()) {
            throw MinibusException::conflict('Cette demande n\'est plus active.');
        }
        if (!$booking->getChecks()->isEmpty()) {
            throw MinibusException::conflict('Un état des lieux est déjà enregistré : l\'emprunt ne peut plus être annulé.');
        }
        $booking->cancel();
        $this->em->flush();
    }

    /**
     * L'emprunteur accepte la charte d'utilisation (présentée dès que la demande est validée). On garde la date et la
     * version du texte accepté ; accepter deux fois la même version ne change rien.
     *
     * @throws MinibusException
     */
    public function acceptCharter(MinibusBooking $booking, User $by): void
    {
        $this->requireBorrower($booking, $by);
        if (!$booking->isApproved()) {
            throw MinibusException::conflict('La charte se valide une fois la demande acceptée par le gestionnaire.');
        }
        $version = $this->settings->current()->getCharterVersion();
        if ($booking->isCharterAccepted() && $booking->getCharterVersion() === $version) {
            return;
        }
        $booking->acceptCharter($version);
        $this->em->flush();
    }

    /**
     * Enregistre (ou complète) l'état des lieux de départ ou de retour de l'emprunteur : photos ajoutées à celles déjà
     * envoyées, texte et indications remplacés.
     *
     * @param array{comment: ?string, mileage: ?int, hasProblem: bool, problemDescription: ?string, refueled: bool, cleaned: bool} $input
     * @param list<UploadedFile>                                                                                                   $files
     *
     * @throws MinibusException
     */
    public function submitCheck(MinibusBooking $booking, User $by, MinibusCheckType $type, array $input, array $files): MinibusCheck
    {
        $this->requireBorrower($booking, $by);
        if (!$booking->isApproved()) {
            throw MinibusException::conflict('L\'état des lieux se remplit une fois la demande validée.');
        }
        if (!$booking->isCharterAccepted()) {
            throw MinibusException::conflict('Acceptez d\'abord la charte d\'utilisation du minibus.');
        }
        if ($type === MinibusCheckType::Departure && new \DateTimeImmutable('today') < $booking->getStartDate()->modify('-1 day')) {
            throw MinibusException::conflict('L\'état des lieux de départ se remplit à partir de la veille de l\'emprunt.');
        }

        $existing = $booking->getCheck($type);
        $already = $existing === null ? 0 : $existing->getPhotos()->count();
        if ($already + count($files) < 1) {
            throw MinibusException::invalid('Ajoutez au moins une photo.');
        }
        if ($already + count($files) > MinibusPhotoService::MAX_PHOTOS_PER_CHECK) {
            throw MinibusException::invalid(sprintf('%d photos au plus par état des lieux.', MinibusPhotoService::MAX_PHOTOS_PER_CHECK));
        }
        $problem = trim((string) $input['problemDescription']);
        if ($input['hasProblem'] && $problem === '') {
            throw MinibusException::invalid('Décrivez le problème rencontré.');
        }
        $mileage = $input['mileage'];
        if ($mileage !== null && ($mileage < 0 || $mileage > self::MAX_MILEAGE)) {
            throw MinibusException::invalid('Kilométrage invalide.');
        }

        $check = $existing ?? new MinibusCheck($booking, $type, $by);
        $added = [];
        try {
            foreach (array_values($files) as $i => $file) {
                $added[] = $this->photos->add($check, $file, $already + $i);
            }
        } catch (MinibusException $e) {
            // Rien n'est enregistré : on retire les fichiers déjà déposés par cet envoi.
            foreach ($added as $photo) {
                $this->photos->deleteFile($photo);
                $check->removePhoto($photo);
                $this->em->detach($photo);
            }
            throw $e;
        }

        if ($existing === null) {
            $booking->addCheck($check);
            $this->em->persist($check);
        }
        $check->setComment($input['comment'])
            ->setProblem($input['hasProblem'], $problem)
            ->setMileage($mileage);
        if ($type === MinibusCheckType::Return) {
            $check->setReturnChecklist($input['refueled'], $input['cleaned']);
        }
        $this->em->flush();

        return $check;
    }

    /**
     * Supprime une photo d'un état des lieux : l'emprunteur (emprunt validé) ou le gestionnaire. Il en reste toujours une.
     *
     * @throws MinibusException
     */
    public function deletePhoto(MinibusCheckPhoto $photo, User $by, bool $isManager): void
    {
        $booking = $photo->getCheck()->getBooking();
        if (!$isManager) {
            $this->requireBorrower($booking, $by);
            if (!$booking->isApproved()) {
                throw MinibusException::conflict('Cet emprunt n\'est plus modifiable.');
            }
        }
        if ($photo->getCheck()->getPhotos()->count() <= 1) {
            throw MinibusException::conflict('Une photo au moins est requise : ajoutez-en une autre avant de supprimer celle-ci.');
        }
        $this->photos->remove($photo);
        $this->em->flush();
    }

    /** Supprime définitivement une demande, ses états des lieux et leurs photos (back-office, administrateurs). */
    public function remove(MinibusBooking $booking): void
    {
        $this->photos->removeAllFiles($booking);
        $this->em->remove($booking);
        $this->em->flush();
    }

    /**
     * Autres demandes en attente ou validées qui chevauchent celle-ci : ce que le gestionnaire doit arbitrer.
     *
     * @return list<MinibusBooking>
     */
    public function conflictsOf(MinibusBooking $booking): array
    {
        return $this->bookings->findOverlapping(
            $booking->getStartDate(),
            $booking->getEndDate(),
            [MinibusBookingStatus::Pending, MinibusBookingStatus::Approved],
            $booking,
        );
    }

    /** @throws MinibusException si une demande validée occupe déjà le minibus sur ces jours */
    private function assertFree(\DateTimeImmutable $start, \DateTimeImmutable $end, ?MinibusBooking $except): void
    {
        $taken = $this->bookings->findOverlapping($start, $end, [MinibusBookingStatus::Approved], $except);
        if ($taken !== []) {
            $first = $taken[0];
            throw MinibusException::conflict(sprintf(
                'Le minibus est déjà réservé du %s au %s (%s).',
                $first->getStartDate()->format('d/m/Y'),
                $first->getEndDate()->format('d/m/Y'),
                $first->getRequesterName(),
            ));
        }
    }

    private function requirePending(MinibusBooking $booking): void
    {
        if (!$booking->isPending()) {
            throw MinibusException::conflict('Cette demande a déjà reçu une réponse : '.mb_strtolower($booking->getStatus()->label()).'.');
        }
    }

    private function requireBorrower(MinibusBooking $booking, User $by): void
    {
        if (!$booking->isRequestedBy($by)) {
            throw MinibusException::forbidden('Seul l\'emprunteur peut faire cette action.');
        }
    }
}
