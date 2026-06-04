<?php

namespace App\MessageHandler;

use App\Message\SendTrainingPlanEmailsMessage;
use App\Repository\TrainingPlanRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class SendTrainingPlanEmailsMessageHandler
{
    public function __construct(
        private readonly TrainingPlanRepository $plans,
        private readonly UserRepository $users,
        private readonly MailerInterface $mailer,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
        private readonly string $publicUrl,
    ) {
    }

    public function __invoke(SendTrainingPlanEmailsMessage $message): void
    {
        $plan = $this->plans->find($message->planId);
        if ($plan === null) {
            // Plan supprimé entre dispatch et consume : silencieux, pas de retry.
            return;
        }

        // Idempotence : si la dispatch a déjà eu lieu, on ne rejoue pas.
        // Les retries de la queue Messenger sont donc safe.
        if ($plan->getEmailsSentAt() !== null) {
            return;
        }

        $recipients = $this->users->findTrainingPlanEmailRecipients($plan);
        if ($recipients === []) {
            // Pas de destinataire : on marque quand même comme "envoyé" pour
            // ne pas retenter à chaque retry.
            $plan->setEmailsSentAt(new \DateTimeImmutable());
            $this->em->flush();
            return;
        }

        // Le lien pointe vers la SPA mobile-web (auth requise — sécurité préservée).
        $trainingUrl = rtrim($this->publicUrl, '/').'/training';

        $sent = 0;
        $failed = 0;
        foreach ($recipients as $user) {
            $email = (new TemplatedEmail())
                ->to($user->getEmail())
                ->subject(sprintf('Nouveau plan d\'entraînement : %s', $plan->getDisplayTitle()))
                ->htmlTemplate('email/training_plan.html.twig')
                ->textTemplate('email/training_plan.txt.twig')
                ->context([
                    'user' => $user,
                    'plan' => $plan,
                    'trainingUrl' => $trainingUrl,
                ]);

            try {
                $this->mailer->send($email);
                $sent++;
            } catch (TransportExceptionInterface $e) {
                $failed++;
                $this->logger->warning('Échec envoi mail plan d\'entraînement', [
                    'planId' => $plan->getId(),
                    'userId' => $user->getId(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // On marque comme envoyé même si certains destinataires ont échoué :
        // sinon un retry rejouerait sur TOUS les destinataires, dont ceux
        // qui ont déjà reçu le mail.
        $plan->setEmailsSentAt(new \DateTimeImmutable());
        $this->em->flush();

        $this->logger->info('Notification plan d\'entraînement', [
            'planId' => $plan->getId(),
            'sent' => $sent,
            'failed' => $failed,
        ]);
    }
}
