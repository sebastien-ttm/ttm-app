<?php

namespace App\MessageHandler;

use App\Message\SendMailingBatchMessage;
use App\Repository\MailingRecipientRepository;
use App\Repository\MailingRepository;
use App\Service\Mailing\MailingMailFactory;
use App\Service\Mailing\MailingService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Envoie un lot de destinataires d'un mailing, puis programme le suivant avec un délai.
 * Le débit est volontairement lent (quelques dizaines de mails par minute) : il reste
 * sous les limites de l'hébergeur de messagerie et évite d'être pris pour un spam.
 *
 * Garde-fous :
 *  - quota quotidien : au-delà de $dailyLimit envois réussis sur 24 h glissantes (tous
 *    mailings confondus), l'envoi attend et reprend tout seul ;
 *  - 3 échecs d'affilée : le mailing est mis en pause (panne SMTP, quota du fournisseur
 *    dépassé…) au lieu de continuer à marteler le serveur ;
 *  - désinscription : revérifiée juste avant chaque envoi ;
 *  - jeton de chaîne : un message périmé (relance manuelle, pause, annulation) est ignoré.
 */
#[AsMessageHandler]
final class SendMailingBatchMessageHandler
{
    private const MAX_CONSECUTIVE_FAILURES = 3;
    private const QUOTA_WAIT_SECONDS = 1800;

    public function __construct(
        private readonly MailingRepository $mailings,
        private readonly MailingRecipientRepository $recipients,
        private readonly MailingService $service,
        private readonly MailingMailFactory $mails,
        private readonly MailerInterface $mailer,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
        #[Autowire('%app.mailing.batch_size%')]
        private readonly int $batchSize,
        #[Autowire('%app.mailing.daily_limit%')]
        private readonly int $dailyLimit,
        #[Autowire('%app.mailing.batch_delay_seconds%')]
        private readonly int $batchDelaySeconds,
    ) {
    }

    public function __invoke(SendMailingBatchMessage $message): void
    {
        $mailing = $this->mailings->find($message->mailingId);
        if ($mailing === null || !$mailing->isSending() || $mailing->getRunToken() !== $message->runToken) {
            return;
        }

        $remaining = $this->dailyLimit - $this->recipients->countSentSince(new \DateTimeImmutable('-24 hours'));
        if ($remaining <= 0) {
            $mailing->setStatusNote(sprintf('En attente : limite de %d mails par 24 h atteinte, l\'envoi reprendra tout seul.', $this->dailyLimit));
            $this->service->dispatchNextBatch($mailing, self::QUOTA_WAIT_SECONDS);

            return;
        }
        $mailing->setStatusNote(null);

        $batch = $this->recipients->findPending($mailing, min($this->batchSize, $remaining));

        $failures = 0;
        $lastError = '';
        foreach ($batch as $recipient) {
            $user = $recipient->getUser();
            if ($user !== null && ($user->isMailingOptedOut() || !$user->isActive())) {
                $recipient->markSkipped('Désinscrit ou compte désactivé depuis le lancement.');
                $this->em->flush();
                continue;
            }

            try {
                $this->mailer->send($this->mails->create(
                    $mailing,
                    $recipient->getEmail(),
                    $user?->getPrenom() ?? '',
                    $user?->getNom() ?? '',
                    $user,
                ));
                $recipient->markSent();
                $failures = 0;
            } catch (\Throwable $e) {
                $recipient->markFailed($e->getMessage());
                $lastError = $e->getMessage();
                ++$failures;
                $this->logger->warning('Échec d\'envoi d\'un mailing', [
                    'mailingId' => $mailing->getId(),
                    'recipientId' => $recipient->getId(),
                    'error' => $e->getMessage(),
                ]);
            }
            $this->em->flush();

            if ($failures >= self::MAX_CONSECUTIVE_FAILURES) {
                $mailing->markPaused(sprintf('Mis en pause automatiquement après %d échecs d\'affilée : %s', $failures, $lastError));
                $mailing->setRunToken('');
                $this->em->flush();
                $this->logger->error('Mailing mis en pause après des échecs d\'affilée', ['mailingId' => $mailing->getId(), 'error' => $lastError]);

                return;
            }
        }

        if ($this->recipients->countsByStatus($mailing)['pending'] === 0) {
            $mailing->markDone();
            $mailing->setRunToken('');
            $this->em->flush();
            $this->logger->info('Mailing terminé', ['mailingId' => $mailing->getId(), 'counts' => $this->recipients->countsByStatus($mailing)]);

            return;
        }

        $this->service->dispatchNextBatch($mailing, $this->batchDelaySeconds);
    }
}
