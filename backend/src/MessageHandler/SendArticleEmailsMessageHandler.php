<?php

namespace App\MessageHandler;

use App\Enum\PushTopic;
use App\Message\SendArticleEmailsMessage;
use App\Repository\ArticleRepository;
use App\Service\WebPush\WebPushNotifier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Notification d'un nouvel article. Il n'y a plus d'e-mail d'article (seuls les plans d'entraînement partent par
 * e-mail) : ce handler n'envoie que le push web. Le nom de la classe et du message est conservé tel quel pour ne pas
 * casser les messages déjà en file.
 */
#[AsMessageHandler]
class SendArticleEmailsMessageHandler
{
    public function __construct(
        private readonly ArticleRepository $articles,
        private readonly EntityManagerInterface $em,
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

        // Claim du verrou AVANT l'envoi (même stratégie que SendTrainingPlanEmailsMessageHandler) : évite les
        // doublons si le worker est tué en cours de route. (La colonne s'appelle encore emails_sent_at.)
        $article->setEmailsSentAt(new \DateTimeImmutable());
        $this->em->flush();

        // Push web (appli installée / navigateur) : chaque adhérent abonné le reçoit sauf s'il a désactivé le sujet
        // « articles ». Règles de visibilité : article dédié à un déplacement / un stage = participants seulement,
        // sinon audience de l'article.
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
    }
}
