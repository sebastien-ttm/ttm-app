<?php

namespace App\Service\Event;

/**
 * Refus d'une opération sur une inscription payante, avec un message montrable tel quel à l'adhérent et le code HTTP
 * correspondant (409 conflit / places insuffisantes, 503 paiement indisponible…). `extra` complète la réponse JSON.
 */
final class EventOrderException extends \RuntimeException
{
    /** @param array<string, mixed> $extra */
    public function __construct(string $message, public readonly int $status = 400, public readonly array $extra = [])
    {
        parent::__construct($message);
    }
}
