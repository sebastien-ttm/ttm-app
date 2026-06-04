<?php

namespace App\EventListener;

use App\Entity\TrainingPlan;
use App\Message\SendPushNotificationsMessage;
use App\Message\SendTrainingPlanEmailsMessage;
use App\Repository\DeviceTokenRepository;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Events;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsDoctrineListener(event: Events::postPersist)]
class TrainingPlanListener
{
    public function __construct(
        private readonly MessageBusInterface $bus,
        private readonly DeviceTokenRepository $deviceTokens,
    ) {
    }

    public function postPersist(PostPersistEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$entity instanceof TrainingPlan) {
            return;
        }

        // 1) Push notification (devices mobiles enregistrés).
        $tokens = $this->deviceTokens->findAllActiveExpoTokens();
        if ($tokens !== []) {
            $this->bus->dispatch(new SendPushNotificationsMessage(
                expoTokens: $tokens,
                title: 'Nouveau plan d\'entraînement',
                body: $entity->getTitle(),
                data: [
                    'type' => 'training_plan',
                    'id' => $entity->getId(),
                ],
            ));
        }

        // 2) Email aux destinataires éligibles (handler s'occupe du filtrage
        //    + idempotence via plan.emailsSentAt).
        if ($entity->getId() !== null) {
            $this->bus->dispatch(new SendTrainingPlanEmailsMessage($entity->getId()));
        }
    }
}
