<?php

namespace App\EventListener;

use App\Entity\TrainingPlan;
use App\Message\SendPushNotificationsMessage;
use App\Message\SendTrainingPlanEmailsMessage;
use App\Repository\DeviceTokenRepository;
use App\Repository\WebPushSubscriptionRepository;
use App\Service\WebPush\WebPushNotifier;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Events;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

#[AsDoctrineListener(event: Events::postPersist)]
class TrainingPlanListener
{
    public function __construct(
        private readonly MessageBusInterface $bus,
        private readonly DeviceTokenRepository $deviceTokens,
        private readonly WebPushNotifier $webPush,
        private readonly WebPushSubscriptionRepository $webPushSubscriptions,
    ) {
    }

    public function postPersist(PostPersistEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$entity instanceof TrainingPlan) {
            return;
        }

        // Différé jusqu'à publishedAt si dans le futur (DelayStamp millisecondes).
        // Note : si l'admin modifie publishedAt après création, l'ancien delay
        // reste en file — le handler ré-évalue au moment du consume et skippe
        // si la date n'est plus atteinte.
        $now = new \DateTimeImmutable();
        $publishedAt = $entity->getPublishedAt();
        $delayMs = 0;
        if ($publishedAt !== null && $publishedAt > $now) {
            $delayMs = ($publishedAt->getTimestamp() - $now->getTimestamp()) * 1000;
        }
        $stamps = $delayMs > 0 ? [new DelayStamp($delayMs)] : [];

        // 1) Push notification (devices mobiles enregistrés), sauf comptes
        //    Jeune qui n'ont pas accès aux plans (User::canSeeTrainingPlans).
        $tokens = $this->deviceTokens->findActiveExpoTokensForTrainingPlans();
        if ($tokens !== []) {
            $this->bus->dispatch(new Envelope(new SendPushNotificationsMessage(
                expoTokens: $tokens,
                title: 'Nouveau plan d\'entraînement',
                body: $entity->getTitle(),
                data: [
                    'type' => 'training_plan',
                    'id' => $entity->getId(),
                ],
            ), $stamps));
        }

        // 1 bis) Push web (appli installée / navigateur) aux mêmes destinataires.
        $this->webPush->notifyUsers(
            $this->webPushSubscriptions->findUserIdsForTrainingPlans(),
            'Nouveau plan d\'entraînement',
            $entity->getTitle(),
            '/training-plan/'.$entity->getId(),
            'training-plan-'.$entity->getId(),
            $stamps,
        );

        // 2) Email aux destinataires éligibles (handler s'occupe du filtrage
        //    + idempotence via plan.emailsSentAt + skip si publishedAt encore futur).
        if ($entity->getId() !== null) {
            $this->bus->dispatch(new Envelope(new SendTrainingPlanEmailsMessage($entity->getId()), $stamps));
        }
    }
}
