<?php

namespace App\Entity;

use App\Repository\MarketplaceSettingsRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Réglages d'accès à la bourse aux équipements (singleton — une seule
 * ligne active, la plus ancienne). Tant qu'aucune ligne n'existe,
 * MarketplaceAccess retombe sur la variable d'environnement
 * MARKETPLACE_TESTER_EMAILS.
 */
#[ORM\Entity(repositoryClass: MarketplaceSettingsRepository::class)]
#[ORM\Table(name: 'marketplace_settings')]
class MarketplaceSettings
{
    public const MODE_CLOSED = 'closed';
    public const MODE_TESTERS = 'testers';
    public const MODE_EVERYONE = 'everyone';
    public const MODES = [self::MODE_CLOSED, self::MODE_TESTERS, self::MODE_EVERYONE];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 16, options: ['default' => self::MODE_TESTERS])]
    #[Assert\Choice(choices: self::MODES)]
    private string $mode = self::MODE_TESTERS;

    /**
     * Adresses e-mail des comptes testeurs, une par ligne (virgules et
     * points-virgules acceptés). Utilisée uniquement en mode « testeurs ».
     */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $testerEmails = null;

    public function getId(): ?int { return $this->id; }

    public function getMode(): string { return $this->mode; }
    public function setMode(string $mode): self
    {
        $this->mode = in_array($mode, self::MODES, true) ? $mode : self::MODE_TESTERS;
        return $this;
    }

    public function getTesterEmails(): ?string { return $this->testerEmails; }
    public function setTesterEmails(?string $raw): self
    {
        $this->testerEmails = $raw !== null && trim($raw) !== '' ? $raw : null;
        return $this;
    }

    /** @return list<string> adresses normalisées (minuscules, dédoublonnées) */
    public function getTesterEmailList(): array
    {
        return self::parseEmailList((string) $this->testerEmails);
    }

    /** Nombre de testeurs — helper d'affichage EA. */
    public function getTesterCount(): int
    {
        return count($this->getTesterEmailList());
    }

    /**
     * Découpe une liste saisie à la main (sauts de ligne, virgules,
     * points-virgules, espaces) en adresses minuscules dédoublonnées.
     *
     * @return list<string>
     */
    public static function parseEmailList(string $raw): array
    {
        $parts = preg_split('/[\s,;]+/', mb_strtolower($raw, 'UTF-8'), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return array_values(array_unique($parts));
    }

    #[Assert\Callback]
    public function validateEmails(ExecutionContextInterface $context): void
    {
        foreach ($this->getTesterEmailList() as $email) {
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $context->buildViolation('« {{ email }} » n\'est pas une adresse e-mail valide.')
                    ->setParameter('{{ email }}', $email)
                    ->atPath('testerEmails')
                    ->addViolation();
            }
        }
    }

    public function __toString(): string
    {
        return 'Réglages de la bourse';
    }
}
