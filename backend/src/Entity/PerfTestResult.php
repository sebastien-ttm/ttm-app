<?php

namespace App\Entity;

use App\Repository\PerfTestResultRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Temps d'un adhérent sur une séance de test, en secondes.
 * Un seul temps par (séance, adhérent).
 *
 * Un ancien adhérent sans compte (temps importés des saisons passées) n'a
 * pas de `user` : seul son nom est conservé dans `legacyName`.
 */
#[ORM\Entity(repositoryClass: PerfTestResultRepository::class)]
#[ORM\Table(name: 'perf_test_result')]
#[ORM\UniqueConstraint(name: 'uniq_perf_result_session_user', columns: ['session_id', 'user_id'])]
#[ORM\UniqueConstraint(name: 'uniq_perf_result_session_legacy', columns: ['session_id', 'legacy_name'])]
class PerfTestResult
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: PerfTestSession::class, inversedBy: 'results')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private PerfTestSession $session;

    /** Null pour un ancien adhérent sans compte (voir legacyName). */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?User $user;

    /** Nom d'un ancien adhérent sans compte, tel qu'écrit dans la feuille importée ; null quand `user` est renseigné. */
    #[ORM\Column(length: 160, nullable: true)]
    private ?string $legacyName = null;

    #[ORM\Column]
    private int $timeSeconds;

    /** Entraîneur qui a saisi (ou modifié en dernier) le temps. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $enteredBy;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(PerfTestSession $session, ?User $user, int $timeSeconds, ?User $enteredBy, ?string $legacyName = null)
    {
        $this->session = $session;
        $this->user = $user;
        $this->legacyName = $user === null ? $legacyName : null;
        $this->setTime($timeSeconds, $enteredBy);
    }

    public function getId(): ?int { return $this->id; }
    public function getSession(): PerfTestSession { return $this->session; }
    public function getUser(): ?User { return $this->user; }
    public function getLegacyName(): ?string { return $this->legacyName; }

    /** Nom à afficher : celui de l'adhérent, ou le nom conservé d'un ancien adhérent. */
    public function getDisplayName(): string
    {
        return $this->user !== null ? $this->user->getFullName() : (string) $this->legacyName;
    }

    /** Clé de tri alphabétique (à égalité de temps). */
    public function getSortName(): string
    {
        return $this->user !== null ? $this->user->getNom() : (string) $this->legacyName;
    }
    public function getTimeSeconds(): int { return $this->timeSeconds; }
    public function getEnteredBy(): ?User { return $this->enteredBy; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    public function setTime(int $timeSeconds, ?User $enteredBy): self
    {
        $this->timeSeconds = $timeSeconds;
        $this->enteredBy = $enteredBy;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    /** « 5:42 » ou « 1:02:15 ». */
    public static function format(int $seconds): string
    {
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);
        $s = $seconds % 60;
        return $h > 0 ? sprintf('%d:%02d:%02d', $h, $m, $s) : sprintf('%d:%02d', $m, $s);
    }

    /**
     * Saisie libre → secondes. Accepte « 5:42 », « 5.42 », « 5'42 »,
     * « 1:02:15 » ou un nombre de secondes seul (« 342 »).
     * null si illisible.
     */
    public static function parse(string $raw): ?int
    {
        // Tout séparateur (« : . ' ’ , h min m s " » et espaces) devient « : ».
        $raw = trim((string) preg_replace('/(?:min|[\s.,\'’"hms:])+/u', ':', mb_strtolower($raw)), ':');
        if (!preg_match('/^\d+(:\d{1,2}){0,2}$/', $raw)) {
            return null;
        }
        $parts = array_map('intval', explode(':', $raw));
        foreach (array_slice($parts, 1) as $p) {
            if ($p >= 60) {
                return null;
            }
        }
        $seconds = 0;
        foreach ($parts as $p) {
            $seconds = $seconds * 60 + $p;
        }
        return $seconds > 0 && $seconds < 24 * 3600 ? $seconds : null;
    }
}
