<?php

namespace App\Service\Csv;

class CsvImportResult
{
    public int $created = 0;
    public int $updated = 0;
    public int $deactivated = 0;
    public int $skipped = 0;
    /**
     * Nombre d'adhérents absents du CSV qui auraient été désactivés
     * MAIS sont restés actifs grâce à la période de grâce.
     */
    public int $deactivationDeferred = 0;
    /** Date jusqu'à laquelle la grâce s'applique (pour le message). */
    public ?\DateTimeImmutable $gracePeriodUntil = null;
    /** @var list<array{line: int, error: string, raw?: array<string, string>}> */
    public array $errors = [];

    public function addError(int $line, string $error, array $raw = []): void
    {
        $this->errors[] = ['line' => $line, 'error' => $error, 'raw' => $raw];
    }

    public function totalProcessed(): int
    {
        return $this->created + $this->updated + $this->skipped;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'created' => $this->created,
            'updated' => $this->updated,
            'deactivated' => $this->deactivated,
            'deactivationDeferred' => $this->deactivationDeferred,
            'gracePeriodUntil' => $this->gracePeriodUntil?->format('Y-m-d'),
            'skipped' => $this->skipped,
            'errors' => $this->errors,
        ];
    }
}
