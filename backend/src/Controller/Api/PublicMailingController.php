<?php

namespace App\Controller\Api;

use App\Repository\UserRepository;
use App\Service\Mailing\MailingUnsubscribeLinks;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Désinscription des mailings du club, sans connexion (lien présent dans chaque mail).
 * Servie sous /api/public/ : seul préfixe routé vers Symfony sans passer par l'appli
 * mobile, et ouvert aux visiteurs non connectés (pare-feu api_public).
 *
 *  - GET  : page de confirmation (un simple GET ne désinscrit JAMAIS : les antivirus et
 *           aperçus de liens des messageries ouvrent les adresses des mails) ;
 *  - POST : désinscrit — c'est aussi la requête de la désinscription « en un clic »
 *           (en-tête List-Unsubscribe-Post) des clients mail, qui n'envoie que
 *           « List-Unsubscribe=One-Click » ; `action=resubscribe` réinscrit.
 *
 * L'autorisation est la signature HMAC dans l'adresse (MailingUnsubscribeLinks).
 */
class PublicMailingController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly MailingUnsubscribeLinks $links,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route(
        '/api/public/mailing/unsubscribe/{userId}/{signature}',
        name: 'public_mailing_unsubscribe',
        methods: ['GET', 'POST'],
        requirements: ['userId' => '\d+', 'signature' => '[a-f0-9]{40}'],
    )]
    public function unsubscribe(int $userId, string $signature, Request $request): Response
    {
        if (!$this->links->isValid($userId, $signature)) {
            throw $this->createNotFoundException();
        }
        $user = $this->users->find($userId) ?? throw $this->createNotFoundException();

        $changed = false;
        if ($request->isMethod('POST')) {
            $user->setMailingOptOut((string) $request->request->get('action', 'unsubscribe') !== 'resubscribe');
            $this->em->flush();
            $changed = true;
        }

        $response = $this->render('public/mailing_unsubscribe.html.twig', [
            'prenom' => $user->getPrenom(),
            'optedOut' => $user->isMailingOptedOut(),
            'changed' => $changed,
        ]);
        // Page personnelle derrière une adresse secrète : ni cache, ni indexation.
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
