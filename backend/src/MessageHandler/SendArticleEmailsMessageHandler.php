<?php

namespace App\MessageHandler;

use App\Enum\PushTopic;
use App\Message\SendArticleEmailsMessage;
use App\Repository\ArticleRepository;
use App\Repository\UserRepository;
use App\Service\WebPush\WebPushNotifier;
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
        private readonly WebPushNotifier $webPush,
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

        // Article dédié à un déplacement encore à l'état de brouillon : ses participants ne sont pas prévenus d'un
        // contenu qu'ils ne peuvent pas ouvrir.
        if ($article->getTrip() !== null && !$article->getTrip()->isPublished()) {
            return;
        }
        if ($article->getStage() !== null && !$article->getStage()->isPublished()) {
            return;
        }

        $recipients = $this->users->findArticleEmailRecipients($article);

        // Claim du verrou AVANT envoi (même stratégie que
        // SendTrainingPlanEmailsMessageHandler) : évite les doublons si
        // le worker est tué mid-loop. Il couvre aussi le push ci-dessous.
        $article->setEmailsSentAt(new \DateTimeImmutable());
        $this->em->flush();

        // Push web (appli installée / navigateur) : indépendant de l'opt-in e-mail — chaque adhérent abonné
        // le reçoit sauf s'il a désactivé le sujet « articles ». Mêmes règles de visibilité que le mail :
        // article dédié à un déplacement / un stage = participants seulement, sinon audience de l'article.
        $trip = $article->getTrip();
        $stage = $article->getStage();
        $participants = $trip?->getParticipants() ?? $stage?->getParticipants();
        $this->webPush->notifyTopic(
            PushTopic::Article,
            'Nouvel article',
            $article->getTitle(),
            '/article/'.$article->getId(),
            'article-'.$article->getId(),
            audience: $participants === null ? $article->getAudience() : [],
            contentAudience: $article->getContentAudience(),
            exclude: $article->getAuthor(),
            recipientFilter: $participants === null ? null : WebPushNotifier::onlyThese($participants),
        );

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
