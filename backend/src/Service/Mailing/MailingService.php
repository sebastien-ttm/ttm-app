<?php

namespace App\Service\Mailing;

use App\Entity\Mailing;
use App\Entity\MailingRecipient;
use App\Entity\User;
use App\Message\SendMailingBatchMessage;
use App\Repository\MailingRecipientRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * Pilotage d'un mailing : audience, lancement, pause, reprise, annulation, relance
 * des échecs et envoi de test. L'envoi proprement dit se fait par lots espacés dans
 * SendMailingBatchMessageHandler (file Messenger traitée par la tâche cron).
 *
 * Les opérations refusées lèvent une \DomainException dont le message, en français,
 * est affiché tel quel à l'administrateur.
 */
final class MailingService
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly MailingRecipientRepository $recipients,
        private readonly MailingMailFactory $mails,
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $bus,
        private readonly MailerInterface $mailer,
    ) {
    }

    /**
     * Qui recevrait le mailing aujourd'hui. Un désinscrit est écarté pour toute son
     * adresse : si un parent et son enfant partagent une adresse et que l'un des deux
     * s'est désinscrit, l'adresse ne reçoit rien.
     *
     * @return array{eligible: list<User>, optedOut: int, invalidEmail: int, duplicates: int}
     */
    public function audience(Mailing $mailing): array
    {
        $candidates = $this->users->findMailingAudience($mailing);

        $optedOutEmails = [];
        foreach ($candidates as $user) {
            if ($user->isMailingOptedOut()) {
                $optedOutEmails[$this->normalize($user->getEmail())] = true;
            }
        }

        $eligible = [];
        $seen = [];
        $optedOut = 0;
        $invalid = 0;
        $duplicates = 0;
        foreach ($candidates as $user) {
            $email = $this->normalize($user->getEmail());
            if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                ++$invalid;
                continue;
            }
            if (isset($optedOutEmails[$email])) {
                ++$optedOut;
                continue;
            }
            if (isset($seen[$email])) {
                ++$duplicates;
                continue;
            }
            $seen[$email] = true;
            $eligible[] = $user;
        }

        return ['eligible' => $eligible, 'optedOut' => $optedOut, 'invalidEmail' => $invalid, 'duplicates' => $duplicates];
    }

    /** Fige la liste des destinataires et lance l'envoi. Renvoie le nombre de destinataires. */
    public function start(Mailing $mailing): int
    {
        if (!$mailing->isDraft()) {
            throw new \DomainException('Ce mailing a déjà été lancé.');
        }
        $audience = $this->audience($mailing);
        if ($audience['eligible'] === []) {
            throw new \DomainException('Aucun destinataire : vérifiez l\'audience du mailing.');
        }

        foreach ($audience['eligible'] as $user) {
            $this->em->persist(new MailingRecipient($mailing, $user));
        }
        $mailing->markSending();
        $this->em->flush();

        $this->dispatchNextBatch($mailing);

        return count($audience['eligible']);
    }

    /** Programme le prochain lot (jeton renouvelé : toute autre chaîne d'envoi s'éteint). */
    public function dispatchNextBatch(Mailing $mailing, int $delaySeconds = 0): void
    {
        $mailing->setRunToken(bin2hex(random_bytes(8)));
        $this->em->flush();

        $stamps = $delaySeconds > 0 ? [new DelayStamp($delaySeconds * 1000)] : [];
        $this->bus->dispatch(new Envelope(new SendMailingBatchMessage((int) $mailing->getId(), $mailing->getRunToken()), $stamps));
    }

    public function pause(Mailing $mailing): void
    {
        if (!$mailing->isSending()) {
            throw new \DomainException('Seul un envoi en cours peut être mis en pause.');
        }
        $mailing->markPaused('Mis en pause par un administrateur.');
        $mailing->setRunToken('');
        $this->em->flush();
    }

    public function resume(Mailing $mailing): void
    {
        if (!$mailing->isPaused()) {
            throw new \DomainException('Seul un envoi en pause peut être repris.');
        }
        $mailing->markSending();
        $this->em->flush();
        $this->dispatchNextBatch($mailing);
    }

    /** Relance le traitement d'un envoi « en cours » qui semble bloqué (file vidée, worker arrêté…). */
    public function nudge(Mailing $mailing): void
    {
        if (!$mailing->isSending()) {
            throw new \DomainException('Seul un envoi en cours peut être relancé.');
        }
        $mailing->setStatusNote(null);
        $this->dispatchNextBatch($mailing);
    }

    public function cancel(Mailing $mailing): void
    {
        if (!$mailing->isSending() && !$mailing->isPaused()) {
            throw new \DomainException('Seul un envoi en cours ou en pause peut être annulé.');
        }
        $mailing->markCancelled();
        $mailing->setRunToken('');
        $this->em->flush();
    }

    /** Remet les envois en échec en attente (et relance l'envoi s'il était terminé). Renvoie leur nombre. */
    public function retryFailed(Mailing $mailing): int
    {
        if ($mailing->isDraft() || $mailing->isCancelled()) {
            throw new \DomainException('Ce mailing n\'a pas d\'envois à relancer.');
        }
        $count = $this->recipients->resetFailed($mailing);
        if ($count === 0) {
            throw new \DomainException('Aucun envoi en échec à relancer.');
        }
        if ($mailing->isDone()) {
            $mailing->markSending();
            $this->em->flush();
            $this->dispatchNextBatch($mailing);
        }

        return $count;
    }

    /**
     * Envoie le mailing à l'administrateur lui-même (objet précédé de « [TEST] »), tout
     * de suite et sans passer par la file. Laisse remonter l'erreur du transport.
     */
    public function sendTest(Mailing $mailing, User $to): void
    {
        $this->mailer->send($this->mails->create($mailing, $to->getEmail(), $to->getPrenom(), $to->getNom(), $to, true));
    }

    private function normalize(string $email): string
    {
        return mb_strtolower(trim($email), 'UTF-8');
    }
}
