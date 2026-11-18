<?php

namespace App\MessageHandler;

use App\Entity\BoardMeeting;
use App\Message\CreateBoardMeetingCalendarEventMessage;
use App\Repository\BoardMeetingRepository;
use App\Repository\UserRepository;
use App\Service\Google\GoogleCalendarClient;
use App\Service\Google\GoogleCalendarException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Crée l'événement Google Agenda d'une réunion du CoDir et envoie l'invitation. Google envoie les invitations.
 *
 * Invités : pour le moment, une adresse générique unique (GOOGLE_CALENDAR_GENERIC_ATTENDEE, par défaut
 * codir@triathlontoulousemetropole.com). Si elle est vide, chaque membre du CoDir et chaque entraîneur (ceux que
 * notifie aussi BoardMeetingNotifier) est invité à son adresse e-mail du club ou, à défaut, à son e-mail personnel
 * (User::getInvitationEmail()).
 *
 * Idempotent : une réunion qui a déjà un événement n'en reçoit pas un second (sauf `replace`). Rien n'est fait si
 * Google Agenda n'est pas configuré ou si la réunion est déjà passée. Une erreur de Google remonte : Messenger
 * retente, puis range le message dans la file des échecs.
 */
#[AsMessageHandler]
class CreateBoardMeetingCalendarEventMessageHandler
{
    public function __construct(
        private readonly BoardMeetingRepository $meetings,
        private readonly UserRepository $users,
        private readonly GoogleCalendarClient $calendar,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
        private readonly string $publicUrl,
        private readonly string $genericAttendee,
        private readonly int $durationMinutes,
    ) {
    }

    public function __invoke(CreateBoardMeetingCalendarEventMessage $message): void
    {
        $meeting = $this->meetings->find($message->meetingId);
        if ($meeting === null) {
            return;
        }
        if (!$this->calendar->isConfigured()) {
            $this->logger->info('Google Agenda non configuré : pas d\'invitation pour la réunion '.$meeting->getId().'.');
            return;
        }
        if (!$meeting->isUpcoming()) {
            $this->logger->info('Réunion '.$meeting->getId().' déjà passée : pas d\'invitation Google Agenda.');
            return;
        }

        if ($meeting->getGoogleEventId() !== null) {
            if (!$message->replace) {
                return;
            }
            try {
                $this->calendar->deleteEvent($meeting->getGoogleEventId());
            } catch (GoogleCalendarException $e) {
                // L'ancien événement n'a pas pu être supprimé (droits, réseau…) : on crée quand même le nouveau.
                $this->logger->warning('Ancien événement Google Agenda non supprimé : '.$e->getMessage());
            }
        }

        $emails = $this->attendeeEmails();
        if ($emails === []) {
            $this->logger->warning('Réunion '.$meeting->getId().' : aucun invité avec une adresse e-mail valide (l\'événement est créé sans invité).');
        }

        $created = $this->calendar->createEvent($this->buildEvent($meeting, $emails));
        $meeting->setGoogleEventId($created['id']);
        $this->em->flush();
    }

    /**
     * L'adresse générique si elle est renseignée ; sinon les membres du CoDir et entraîneurs actifs : adresse du club, à
     * défaut personnelle ; adresses valides, sans doublon (parents et enfants peuvent partager une boîte).
     *
     * @return list<string>
     */
    private function attendeeEmails(): array
    {
        $generic = trim($this->genericAttendee);
        if ($generic !== '') {
            if (filter_var($generic, \FILTER_VALIDATE_EMAIL) === false) {
                $this->logger->warning('GOOGLE_CALENDAR_GENERIC_ATTENDEE n\'est pas une adresse valide : '.$generic);
                return [];
            }
            return [$generic];
        }

        $emails = [];
        foreach ([...$this->users->findCommitteeMembers(), ...$this->users->findCoaches()] as $user) {
            if (!$user->isActive()) {
                continue;
            }
            $address = trim($user->getInvitationEmail());
            if (filter_var($address, \FILTER_VALIDATE_EMAIL) === false) {
                continue;
            }
            $emails[mb_strtolower($address, 'UTF-8')] = $address;
        }

        return array_values($emails);
    }

    /**
     * @param list<string> $emails
     *
     * @return array<string, mixed>
     */
    private function buildEvent(BoardMeeting $meeting, array $emails): array
    {
        $start = $meeting->getScheduledAt();
        $end = $start->modify('+'.max(15, $this->durationMinutes).' minutes');
        $agendaUrl = rtrim($this->publicUrl, '/').'/board/'.$meeting->getId();

        $description = "Réunion du comité directeur de Triathlon Toulouse Métropole.\n"
            ."Ordre du jour : ajoutez vos points dans l'application — ".$agendaUrl;
        if ($meeting->getNotes() !== null) {
            $description .= "\n\n".$meeting->getNotes();
        }

        $event = [
            'summary' => 'TTM · '.$meeting->getLabel(),
            'description' => $description,
            'start' => ['dateTime' => $start->format(\DATE_ATOM), 'timeZone' => 'Europe/Paris'],
            'end' => ['dateTime' => $end->format(\DATE_ATOM), 'timeZone' => 'Europe/Paris'],
            'attendees' => array_map(static fn (string $email) => ['email' => $email], $emails),
            'guestsCanModify' => false,
            'reminders' => ['useDefault' => true],
            // Repère pour retrouver la réunion depuis Google Agenda (synchronisation future).
            'extendedProperties' => ['private' => ['ttmBoardMeetingId' => (string) $meeting->getId()]],
        ];
        if ($meeting->getLocation() !== null) {
            $event['location'] = $meeting->getLocation();
        }

        return $event;
    }
}
