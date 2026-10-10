<?php

namespace App\Service\Mailing;

use App\Entity\Mailing;
use App\Entity\User;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;

/**
 * Fabrique le courriel d'un mailing pour un destinataire : contenu personnalisé
 * ({{ prenom }}, {{ nom }}), version texte, lien de désinscription dans le pied de
 * page et en-têtes List-Unsubscribe / List-Unsubscribe-Post (désinscription en un
 * clic, exigée par Gmail et Yahoo pour les envois en nombre).
 */
final class MailingMailFactory
{
    public function __construct(private readonly MailingUnsubscribeLinks $links)
    {
    }

    public function create(Mailing $mailing, string $toEmail, string $prenom, string $nom, ?User $user, bool $test = false): TemplatedEmail
    {
        $context = $this->context($mailing, $prenom, $nom, $user);

        $email = (new TemplatedEmail())
            ->to($toEmail)
            ->subject(($test ? '[TEST] ' : '').$mailing->getSubject())
            ->htmlTemplate('email/mailing.html.twig')
            ->textTemplate('email/mailing.txt.twig')
            ->context($context);

        if ($mailing->getReplyTo() !== null) {
            $email->replyTo($mailing->getReplyTo());
        }

        $headers = $email->getHeaders();
        $headers->addTextHeader('Precedence', 'bulk');
        $headers->addTextHeader('X-Mailing-Id', (string) $mailing->getId());
        if ($context['unsubscribeUrl'] !== null) {
            $headers->addTextHeader('List-Unsubscribe', '<'.$context['unsubscribeUrl'].'>');
            $headers->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
        }

        return $email;
    }

    /**
     * Variables des gabarits email/mailing.html.twig et .txt.twig ; sert aussi à
     * l'aperçu de l'écran d'administration.
     *
     * @return array{mailingSubject: string, bodyHtml: string, bodyText: string, unsubscribeUrl: ?string}
     */
    public function context(Mailing $mailing, string $prenom, string $nom, ?User $user): array
    {
        $html = $this->personalize($mailing->getBodyHtml(), $prenom, $nom);

        return [
            'mailingSubject' => $mailing->getSubject(),
            'bodyHtml' => $html,
            'bodyText' => $this->toText($html),
            'unsubscribeUrl' => $user !== null ? $this->links->urlFor($user) : null,
        ];
    }

    /** Remplace {{ prenom }} et {{ nom }} (valeurs échappées : jamais de HTML venu d'un nom). */
    private function personalize(string $html, string $prenom, string $nom): string
    {
        return (string) preg_replace_callback(
            '/\{\{\s*(prenom|nom)\s*\}\}/iu',
            static fn (array $m): string => htmlspecialchars(
                mb_strtolower($m[1], 'UTF-8') === 'prenom' ? $prenom : $nom,
                ENT_QUOTES | ENT_SUBSTITUTE,
                'UTF-8',
            ),
            $html,
        );
    }

    /** Version texte brut du HTML : paragraphes, listes et liens (« texte (adresse) ») conservés. */
    private function toText(string $html): string
    {
        $html = (string) preg_replace_callback(
            '#<a\b[^>]*\bhref=(["\'])(.*?)\1[^>]*>(.*?)</a>#is',
            static fn (array $m): string => trim(strip_tags($m[3])).' ('.$m[2].')',
            $html,
        );
        $html = (string) preg_replace('#<li\b[^>]*>#i', '- ', $html);
        $html = (string) preg_replace('#</(p|h[1-6]|blockquote)>#i', "\n\n", $html);
        $html = (string) preg_replace('#<br\s*/?>|<(?:ul|ol)\b[^>]*>|</(div|li|tr|ul|ol)>#i', "\n", $html);

        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = (string) preg_replace("/[ \t]+\n/", "\n", $text);
        $text = (string) preg_replace("/\n{3,}/", "\n\n", $text);

        return trim($text);
    }
}
