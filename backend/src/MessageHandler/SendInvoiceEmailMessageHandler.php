<?php

namespace App\MessageHandler;

use App\Message\SendInvoiceEmailMessage;
use App\Repository\TrainingSeasonRepository;
use App\Repository\UserRepository;
use App\Repository\UserSeasonMembershipRepository;
use App\Service\Invoice\InvoiceService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class SendInvoiceEmailMessageHandler
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly TrainingSeasonRepository $seasons,
        private readonly UserSeasonMembershipRepository $memberships,
        private readonly InvoiceService $invoiceService,
        private readonly MailerInterface $mailer,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(SendInvoiceEmailMessage $message): void
    {
        $user = $this->users->find($message->userId);
        $season = $this->seasons->find($message->seasonId);
        if ($user === null || $season === null) {
            $this->logger->warning('Invoice dispatch : user/saison introuvable', $message);
            return;
        }
        $email = $user->getEmail();
        if (!$email) {
            $this->logger->warning('Invoice dispatch : email absent', ['userId' => $user->getId()]);
            return;
        }
        $membership = $this->memberships->findOneByUserAndSeason($user, $season);
        if ($membership === null) {
            $this->logger->warning('Invoice dispatch : membership absente', ['userId' => $user->getId(), 'seasonId' => $season->getId()]);
            return;
        }

        try {
            $pdf = $this->invoiceService->renderPdf($user, $season);
        } catch (\Throwable $e) {
            $this->logger->error('Invoice dispatch : échec génération PDF', ['error' => $e->getMessage()]);
            return;
        }

        $mail = (new TemplatedEmail())
            ->to($email)
            ->subject(sprintf('Votre facture d\'adhésion %s', (string) $season))
            ->htmlTemplate('email/invoice.html.twig')
            ->textTemplate('email/invoice.txt.twig')
            ->context([
                'user' => $user,
                'season' => $season,
            ])
            ->attach($pdf, $this->invoiceService->suggestedFilename($user, $season), 'application/pdf');

        $this->mailer->send($mail);

        $membership->markInvoiced();
        $this->em->flush();
    }
}
