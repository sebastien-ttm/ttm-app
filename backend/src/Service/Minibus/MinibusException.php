<?php

namespace App\Service\Minibus;

/**
 * Règle du prêt du minibus non respectée. Le message est destiné à l'utilisateur (en français) ; `getStatus()` donne le
 * code HTTP que l'API renvoie : 422 saisie invalide, 409 état incompatible (créneau déjà pris, étape non disponible…),
 * 403 action réservée à quelqu'un d'autre.
 */
final class MinibusException extends \RuntimeException
{
    private function __construct(string $message, private readonly int $status)
    {
        parent::__construct($message);
    }

    public static function invalid(string $message): self
    {
        return new self($message, 422);
    }

    public static function conflict(string $message): self
    {
        return new self($message, 409);
    }

    public static function forbidden(string $message): self
    {
        return new self($message, 403);
    }

    public function getStatus(): int
    {
        return $this->status;
    }
}
