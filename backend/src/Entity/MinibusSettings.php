<?php

namespace App\Entity;

use App\Repository\MinibusSettingsRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Réglages du prêt du minibus (singleton — une seule ligne active, la plus ancienne) : la charte d'utilisation, modifiable
 * depuis le back-office, présentée à l'emprunteur dès que sa demande est validée. Tant qu'aucune ligne n'existe, le texte
 * par défaut ci-dessous est utilisé (voir MinibusSettingsRepository::current()).
 */
#[ORM\Entity(repositoryClass: MinibusSettingsRepository::class)]
#[ORM\Table(name: 'minibus_settings')]
class MinibusSettings
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'charter_title', length: 200)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 200)]
    private string $charterTitle;

    /** Texte de la charte, HTML riche (éditeur du back-office). */
    #[ORM\Column(name: 'charter_content', type: 'text')]
    #[Assert\NotBlank]
    private string $charterContent;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->charterTitle = self::defaultTitle();
        $this->charterContent = self::defaultContent();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public static function defaultTitle(): string
    {
        return 'Charte d\'utilisation du minibus TTM';
    }

    /** Texte de départ, repris de la fiche plastifiée laissée dans le minibus. */
    public static function defaultContent(): string
    {
        // Mise en forme volontairement simple (blocs <div>, gras, liste) : c'est ce que l'éditeur du back-office (Trix) produit.
        return trim(<<<'HTML'
            <div>Bienvenue dans le minibus TTM ! Il est mis gratuitement à votre disposition par le club : nous vous demandons donc de le rendre dans le même état que vous l'avez trouvé.</div>
            <div><br></div>
            <div><strong>Avant le départ</strong></div>
            <div>Avant de prendre la route, faites un tour complet du minibus et de la remorque, puis remplissez l'état des lieux de départ dans l'application : photos, kilométrage, remarques éventuelles.</div>
            <div><br></div>
            <div><strong>Pendant l'emprunt</strong></div>
            <div>Indiquez dans l'état des lieux tout problème rencontré (dégât, panne, incident), même mineur.</div>
            <div><br></div>
            <div><strong>Au retour</strong></div>
            <ul>
            <li>Refaites le plein du minibus.</li>
            <li>Nettoyez le minibus et videz-le de tout détritus.</li>
            <li>Remplissez l'état des lieux de retour dans l'application : photos, comment cela s'est passé et problèmes éventuels.</li>
            </ul>
            <div><br></div>
            <div>En acceptant cette charte, vous vous engagez à en respecter les règles.</div>
            HTML);
    }

    public function getId(): ?int { return $this->id; }

    public function getCharterTitle(): string { return $this->charterTitle; }
    public function setCharterTitle(string $title): self
    {
        $this->charterTitle = trim($title);
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function getCharterContent(): string { return $this->charterContent; }
    public function setCharterContent(string $content): self
    {
        $this->charterContent = $content;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    /**
     * Empreinte du texte de la charte : enregistrée avec l'acceptation de l'emprunteur pour savoir quelle version il a lue.
     */
    public function getCharterVersion(): string
    {
        return substr(sha1($this->charterTitle."\n".$this->charterContent), 0, 12);
    }

    public function __toString(): string
    {
        return 'Charte du minibus';
    }
}
