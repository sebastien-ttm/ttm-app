<?php

namespace App\MessageHandler;

use App\Message\NotifyMarketplaceMessageMessage;
use App\Repository\MarketplaceMessageRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Envoie à l'INTERLOCUTEUR de l'auteur un e-mail pour chaque message de
 * la bourse (premier contact comme réponses) — l'auteur lui-même n'est
 * jamais notifié de son propre message.
 *
 * Aucun e-mail si le destinataire est désactivé ; un compte sans adresse
 * n'existe pas (User::$email est obligatoire).
 */
#[AsMessageHandler]
class NotifyMarketplaceMessageMessageHandler
{
    public function __construct(
        private readonly MarketplaceMessageRepository $messages,
        private readonly MailerInterface $mailer,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
        private readonly string $publicUrl,
    ) {
    }

    public function __invoke(NotifyMarketplaceMessageMessage $dispatched): void
    {
        $message = $this->messages->find($dispatched->messageId);
        if ($message === null || $message->getNotifiedAt() !== null) {
            return; // introuvable ou déjà notifié (idempotence)
        }

        $conversation = $message->getConversation();
        $author = $message->getAuthor();
        $recipient = $conversation->getOtherParticipant($author);

        // Claim AVANT envoi : si le worker meurt en plein envoi, on
        // préfère un e-mail manquant à un doublon (même stratégie que
        // les autres notifications du module).
        $message->setNotifiedAt(new \DateTimeImmutable());
        $this->em->flush();

        if (!$recipient->isActive()) {
            return;
        }

        $subjectTitle = $conversation->getSubjectTitle();
        $email = (new TemplatedEmail())
            ->to($recipient->getEmail())
            ->subject(sprintf('%s vous a écrit à propos de « %s »', $author->getFullName(), $subjectTitle))
            ->htmlTemplate('email/marketplace_message.html.twig')
            ->textTemplate('email/marketplace_message.txt.twig')
            ->context([
                'recipient' => $recipient,
                'author' => $author,
                // Annonce ou offre de dossards : les templates n'utilisent que le titre.
                'listing' => ['title' => $subjectTitle],
                'bourseLabel' => $conversation->getSubjectKind() === 'bib'
                    ? 'la bourse aux dossards'
                    : 'la bourse aux équipements',
                'subjectNoun' => $conversation->getSubjectKind() === 'bib' ? 'offre' : 'annonce',
                'message' => $message,
                'recipientIsSeller' => $recipient->getId() === $conversation->getSeller()->getId(),
                'conversationUrl' => rtrim($this->publicUrl, '/').'/marketplace/conversation/'.$conversation->getId(),
            ]);

        try {
            $this->mailer->send($email);
        } catch (TransportExceptionInterface $e) {
            $this->logger->warning('Échec notif message bourse', [
                'messageId' => $message->getId(),
                'recipientId' => $recipient->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
