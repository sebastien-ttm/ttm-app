<?php

namespace App\MessageHandler;

use App\Message\SendArticleEmailsMessage;
use App\Repository\ArticleRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class SendArticleEmailsMessageHandler
{
    public function __construct(
        private readonly ArticleRepository $articles,
        private readonly UserRepository $users,
        private readonly MailerInterface $mailer,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
        private readonly string $publicUrl,
    ) {
    }

    public function __invoke(SendArticleEmailsMessage $message): void
    {
        $article = $this->articles->find($message->articleId);
        if ($article === null) {
            return;
        }

        // Idempotence : si la dispatch a déjà eu lieu, on ne rejoue pas.
        if ($article->getEmailsSentAt() !== null) {
            return;
        }

        // L'admin a peut-être décoché « notifier à la publication » ou
        // programmé la publication dans le futur. Dans les deux cas on
        // skippe : la re-cocher n'est pas gérée automatiquement (il
        // faudra un déclencheur manuel côté admin si besoin).
        if (!$article->isNotifyOnPublish() || !$article->isPublished()) {
            return;
        }

        $recipients = $this->users->findArticleEmailRecipients($article);

        // Claim du verrou AVANT envoi (même stratégie que
        // SendTrainingPlanEmailsMessageHandler) : évite les doublons si
        // le worker est tué mid-loop.
        $article->setEmailsSentAt(new \DateTimeImmutable());
        $this->em->flush();

        if ($recipients === []) {
            return;
        }

        // Lien vers la page détail de l'article dans la SPA.
        $articleUrl = rtrim($this->publicUrl, '/').'/article/'.$article->getId();

        $sent = 0;
        $failed = 0;
        foreach ($recipients as $user) {
            $email = (new TemplatedEmail())
                ->to($user->getEmail())
                ->subject(sprintf('Nouvel article : %s', $article->getTitle()))
                ->htmlTemplate('email/article.html.twig')
                ->textTemplate('email/article.txt.twig')
                ->context([
                    'user' => $user,
                    'article' => $article,
                    'articleUrl' => $articleUrl,
                ]);

            try {
                $this->mailer->send($email);
                $sent++;
            } catch (TransportExceptionInterface $e) {
                $failed++;
                $this->logger->warning('Échec envoi mail article', [
                    'articleId' => $article->getId(),
                    'userId' => $user->getId(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->logger->info('Notification article', [
            'articleId' => $article->getId(),
            'sent' => $sent,
            'failed' => $failed,
            'recipients' => count($recipients),
        ]);
    }
}
