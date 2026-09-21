<?php

namespace App\EventListener;

use App\Entity\Article;
use App\Message\SendArticleEmailsMessage;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Events;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * Dispatche les emails de notification aux adhérents opt-in quand un
 * nouvel article est créé, si notifyOnPublish est coché. Miroir de
 * TrainingPlanListener côté emails — pas de push notif article pour
 * l'instant.
 */
#[AsDoctrineListener(event: Events::postPersist)]
class ArticleListener
{
    public function __construct(
        private readonly MessageBusInterface $bus,
    ) {
    }

    public function postPersist(PostPersistEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$entity instanceof Article) {
            return;
        }
        if (!$entity->isNotifyOnPublish()) {
            return;
        }
        if ($entity->getId() === null) {
            return;
        }

        // Différé jusqu'à publishedAt si programmé dans le futur — même
        // stratégie que TrainingPlanListener.
        $now = new \DateTimeImmutable();
        $publishedAt = $entity->getPublishedAt();
        $delayMs = 0;
        if ($publishedAt !== null && $publishedAt > $now) {
            $delayMs = ($publishedAt->getTimestamp() - $now->getTimestamp()) * 1000;
        }
        $stamps = $delayMs > 0 ? [new DelayStamp($delayMs)] : [];

        $this->bus->dispatch(new Envelope(new SendArticleEmailsMessage($entity->getId()), $stamps));
    }
}
