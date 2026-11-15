<?php

namespace App\EventListener;

use App\Entity\BibOffer;
use App\Entity\Event;
use App\Entity\MarketplaceListing;
use App\Entity\RaceProposal;
use App\Entity\StaticPage;
use App\Entity\Survey;
use App\Entity\User;
use App\Enum\PushTopic;
use App\Service\Marketplace\MarketplaceAccess;
use App\Service\WebPush\WebPushNotifier;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Events;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * Notifications push « nouveau contenu » : événement, page du club, sondage, compétition proposée, annonce
 * d'occasion, dossard à céder (les plans d'entraînement, articles et albums photo ont leur propre déclencheur).
 *
 * Chaque adhérent abonné ne reçoit que les sujets qu'il n'a pas désactivés (profil → Notifications push), et
 * seulement s'il peut voir le contenu (audience, stage, accès à la bourse). L'auteur n'est jamais prévenu.
 * Un contenu enregistré en brouillon n'est pas annoncé (et ne l'est pas non plus quand il est publié ensuite).
 *
 * Une panne du push ne doit jamais empêcher d'enregistrer le contenu : tout est protégé par un try/catch.
 */
#[AsDoctrineListener(event: Events::postPersist)]
class PushTopicListener
{
    public function __construct(
        private readonly WebPushNotifier $webPush,
        private readonly MarketplaceAccess $marketplaceAccess,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function postPersist(PostPersistEventArgs $args): void
    {
        $entity = $args->getObject();

        try {
            match (true) {
                $entity instanceof Event => $this->onEvent($entity),
                $entity instanceof StaticPage => $this->onStaticPage($entity),
                $entity instanceof Survey => $this->onSurvey($entity),
                $entity instanceof RaceProposal => $this->onRaceProposal($entity),
                $entity instanceof MarketplaceListing => $this->onMarketplaceListing($entity),
                $entity instanceof BibOffer => $this->onBibOffer($entity),
                default => null,
            };
        } catch (\Throwable $e) {
            $this->logger->warning('Échec de la notification push (nouveau contenu)', [
                'entity' => $entity::class,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function onEvent(Event $event): void
    {
        // Événement déjà terminé (saisi après coup) : rien à annoncer.
        $end = $event->getEndsAt() ?? $event->getStartsAt();
        if ($event->isAllDay()) {
            $end = $end->modify('+1 day');
        }
        if ($end < new \DateTimeImmutable()) {
            return;
        }

        // Événement dédié à un stage : seuls ses participants peuvent l'ouvrir.
        $stage = $event->getStage();
        $this->webPush->notifyTopic(
            PushTopic::Event,
            'Nouvel événement',
            sprintf('%s — %s', $event->getTitle(), $event->getStartsAt()->format('d/m/Y')),
            '/event/'.$event->getId(),
            'event-'.$event->getId(),
            audience: $stage === null ? $event->getAudience() : [],
            contentAudience: $event->getContentAudience(),
            exclude: $event->getCreatedBy(),
            recipientFilter: $stage === null ? null : WebPushNotifier::onlyThese($stage->getParticipants()),
        );
    }

    private function onStaticPage(StaticPage $page): void
    {
        // Page (ou page parente) masquée : rien à annoncer.
        for ($p = $page; $p !== null; $p = $p->getParent()) {
            if (!$p->isPublished()) {
                return;
            }
        }

        $this->webPush->notifyTopic(
            PushTopic::ClubPage,
            'Nouvelle page du club',
            $page->getTitle(),
            '/page/'.$page->getSlug(),
            'page-'.$page->getId(),
            audience: $page->getAudience(),
            contentAudience: $page->getContentAudience(),
            exclude: $page->getCreatedBy(),
        );
    }

    private function onSurvey(Survey $survey): void
    {
        // Brouillon (pas de date de publication) ou déjà clos : rien à annoncer.
        $publishedAt = $survey->getPublishedAt();
        if ($publishedAt === null || $survey->isClosed()) {
            return;
        }

        $stage = $survey->getStage();
        $this->webPush->notifyTopic(
            PushTopic::Survey,
            'Nouveau sondage',
            $survey->getTitle(),
            '/survey/'.$survey->getId(),
            'survey-'.$survey->getId(),
            audience: $stage === null ? $survey->getAudience() : [],
            exclude: $survey->getCreatedBy(),
            recipientFilter: $stage === null ? null : WebPushNotifier::onlyThese($stage->getParticipants()),
            stamps: self::delayUntil($publishedAt),
        );
    }

    private function onRaceProposal(RaceProposal $race): void
    {
        $this->webPush->notifyTopic(
            PushTopic::Race,
            'Compétition proposée',
            sprintf('%s — %s', $race->getName(), $race->getRaceDate()->format('d/m/Y')),
            '/races/'.$race->getId(),
            'race-'.$race->getId(),
            exclude: $race->getAuthor(),
        );
    }

    private function onMarketplaceListing(MarketplaceListing $listing): void
    {
        $this->webPush->notifyTopic(
            PushTopic::Marketplace,
            'Nouvelle annonce d\'occasion',
            $listing->getTitle(),
            '/marketplace/'.$listing->getId(),
            'marketplace-'.$listing->getId(),
            exclude: $listing->getAuthor(),
            recipientFilter: fn (User $user): bool => $this->marketplaceAccess->isEnabledFor($user),
        );
    }

    private function onBibOffer(BibOffer $offer): void
    {
        $quantity = $offer->getQuantity();

        $this->webPush->notifyTopic(
            PushTopic::Bib,
            'Dossard à céder',
            sprintf('%s — %d dossard%s', $offer->getRaceName(), $quantity, $quantity > 1 ? 's' : ''),
            '/bibs/'.$offer->getId(),
            'bib-'.$offer->getId(),
            exclude: $offer->getAuthor(),
            // Les dossards font partie de la bourse aux équipements : mêmes adhérents autorisés.
            recipientFilter: fn (User $user): bool => $this->marketplaceAccess->isEnabledFor($user),
        );
    }

    /** @return list<DelayStamp> */
    private static function delayUntil(?\DateTimeImmutable $at): array
    {
        $now = time();
        if ($at === null || $at->getTimestamp() <= $now) {
            return [];
        }

        return [new DelayStamp(($at->getTimestamp() - $now) * 1000)];
    }
}
