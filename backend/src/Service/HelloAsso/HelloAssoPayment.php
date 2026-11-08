<?php

namespace App\Service\HelloAsso;

/**
 * Vente HelloAsso valide (un « item » : billet, adhésion, article…), réduite à ce qui sert au rapprochement avec
 * les adhérents. `user` = la personne inscrite (participant, adhérent) ; `payer` = qui a payé (souvent un parent).
 */
final class HelloAssoPayment
{
    public function __construct(
        public readonly string $id,
        public readonly ?string $payerEmail,
        public readonly ?string $payerFirstName,
        public readonly ?string $payerLastName,
        public readonly ?string $userFirstName,
        public readonly ?string $userLastName,
        /** Montant en euros. */
        public readonly float $amount,
        public readonly ?\DateTimeImmutable $date,
        /** Tarif / article (« Adulte », « T-shirt M »…). */
        public readonly ?string $tierName,
        public readonly string $state,
    ) {
    }

    /** @param array<string, mixed> $row  élément de la réponse /forms/{type}/{slug}/items */
    public static function fromApi(array $row): self
    {
        $payer = is_array($row['payer'] ?? null) ? $row['payer'] : [];
        $user = is_array($row['user'] ?? null) ? $row['user'] : [];
        $order = is_array($row['order'] ?? null) ? $row['order'] : [];
        $rawDate = $order['date'] ?? null;
        try {
            $date = is_string($rawDate) && $rawDate !== '' ? new \DateTimeImmutable($rawDate) : null;
        } catch (\Exception) {
            $date = null;
        }

        return new self(
            (string) ($row['id'] ?? ''),
            self::str($payer['email'] ?? null),
            self::str($payer['firstName'] ?? null),
            self::str($payer['lastName'] ?? null),
            self::str($user['firstName'] ?? null),
            self::str($user['lastName'] ?? null),
            // Les montants HelloAsso sont en centimes.
            ((int) ($row['amount'] ?? 0)) / 100,
            $date?->setTimezone(new \DateTimeZone('Europe/Paris')),
            self::str($row['name'] ?? null),
            (string) ($row['state'] ?? ''),
        );
    }

    public function participantName(): string
    {
        $user = trim(($this->userFirstName ?? '').' '.($this->userLastName ?? ''));
        return $user !== '' ? $user : trim(($this->payerFirstName ?? '').' '.($this->payerLastName ?? ''));
    }

    public function payerName(): string
    {
        return trim(($this->payerFirstName ?? '').' '.($this->payerLastName ?? ''));
    }

    private static function str(mixed $v): ?string
    {
        return is_string($v) && trim($v) !== '' ? trim($v) : null;
    }
}
