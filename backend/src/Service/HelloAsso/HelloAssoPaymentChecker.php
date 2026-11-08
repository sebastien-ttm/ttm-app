<?php

namespace App\Service\HelloAsso;

use App\Entity\Event;
use App\Entity\User;

/**
 * Rapproche les ventes HelloAsso du formulaire lié à un événement et les adhérents concernés.
 *
 * Règle : l'email d'abord, puis le nom.
 *  1. Email du payeur = email de l'adhérent. Plusieurs comptes partagent un email (parent et enfants) : on garde
 *     alors celui dont le nom correspond au participant inscrit, ou tous si aucun ne correspond.
 *  2. Sinon, nom + prénom du participant inscrit (puis du payeur) = nom + prénom de l'adhérent
 *     (sans accents, casse ni tirets).
 */
class HelloAssoPaymentChecker
{
    public function __construct(private readonly HelloAssoClient $client)
    {
    }

    public function isConfigured(): bool
    {
        return $this->client->isConfigured();
    }

    /**
     * @param list<User> $users  adhérents à vérifier (inscrits « J'y serai », émargés…)
     * @return array{
     *     linked: bool,
     *     form: array{organizationSlug: string, formType: string, formSlug: string, typeLabel: string, url: string}|null,
     *     error: ?string,
     *     paidByUser: array<int, list<HelloAssoPayment>>,
     *     unmatched: list<HelloAssoPayment>,
     *     total: int
     * }
     */
    public function check(Event $event, array $users, bool $refresh = false): array
    {
        $form = HelloAssoClient::parseFormUrl($event->getHelloassoFormUrl());
        $result = [
            'linked' => $form !== null,
            'form' => $form !== null ? $form + [
                'typeLabel' => HelloAssoClient::FORM_TYPE_LABELS[$form['formType']] ?? $form['formType'],
                'url' => (string) $event->getHelloassoFormUrl(),
            ] : null,
            'error' => null,
            'paidByUser' => [],
            'unmatched' => [],
            'total' => 0,
        ];
        if ($form === null) {
            return $result;
        }
        if (!$this->client->isConfigured()) {
            $result['error'] = 'HelloAsso n\'est pas encore configuré sur le serveur (identifiants API à renseigner).';
            return $result;
        }

        try {
            $payments = $this->client->fetchPaidItems($form['organizationSlug'], $form['formType'], $form['formSlug'], $refresh);
        } catch (HelloAssoException $e) {
            $result['error'] = $e->getMessage();
            return $result;
        }
        $result['total'] = count($payments);

        $byEmail = [];
        $byName = [];
        foreach ($users as $u) {
            $byEmail[self::email($u->getEmail())][] = $u;
            $byName[self::name($u->getPrenom(), $u->getNom())][] = $u;
        }

        foreach ($payments as $p) {
            $matched = $this->match($p, $byEmail, $byName);
            if ($matched === []) {
                $result['unmatched'][] = $p;
                continue;
            }
            foreach ($matched as $u) {
                $result['paidByUser'][(int) $u->getId()][] = $p;
            }
        }

        return $result;
    }

    /**
     * @param array<string, list<User>> $byEmail
     * @param array<string, list<User>> $byName
     * @return list<User>
     */
    private function match(HelloAssoPayment $p, array $byEmail, array $byName): array
    {
        $participant = self::name($p->userFirstName, $p->userLastName);
        $payer = self::name($p->payerFirstName, $p->payerLastName);

        $email = self::email($p->payerEmail);
        if ($email !== '' && isset($byEmail[$email])) {
            $candidates = $byEmail[$email];
            if (count($candidates) === 1) {
                return $candidates;
            }
            $named = array_values(array_filter(
                $candidates,
                static fn (User $u) => in_array(self::name($u->getPrenom(), $u->getNom()), array_filter([$participant, $payer]), true),
            ));
            return $named !== [] ? $named : $candidates;
        }

        foreach ([$participant, $payer] as $key) {
            if ($key !== '' && isset($byName[$key])) {
                return $byName[$key];
            }
        }
        return [];
    }

    private static function email(?string $email): string
    {
        return mb_strtolower(trim((string) $email));
    }

    /** « Jean-Pierre  Dupont » → « jean pierre dupont » (sans accents, tirets ni espaces multiples). */
    public static function name(?string $first, ?string $last): string
    {
        $full = trim(($first ?? '').' '.($last ?? ''));
        if ($full === '') {
            return '';
        }
        $ascii = \transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $full);
        $ascii = is_string($ascii) ? $ascii : mb_strtolower($full);
        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', $ascii));
    }
}
