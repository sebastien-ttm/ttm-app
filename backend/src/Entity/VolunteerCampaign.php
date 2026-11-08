<?php

namespace App\Entity;

use App\Repository\VolunteerCampaignRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Appel à bénévoles d'un événement organisé par le club (ex. Breathman 2027) : des pôles, chacun avec ses
 * activités (référent·es, engagement en amont) et ses postes terrain (responsables d'équipe les jours J).
 * Les adhérents se positionnent sur les fiches publiées ; les responsables de chaque pôle valident.
 *
 * `published` rend la campagne visible dans l'appli ; `applicationsOpen` autorise les candidatures (on peut
 * laisser les fiches visibles après la clôture des candidatures).
 */
#[ORM\Entity(repositoryClass: VolunteerCampaignRepository::class)]
#[ORM\Table(name: 'volunteer_campaign')]
#[ORM\UniqueConstraint(name: 'uniq_volunteer_campaign_slug', columns: ['slug'])]
class VolunteerCampaign
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 120)]
    private string $name = '';

    /** Identifiant stable utilisé par la commande d'import (ex. « breathman-2027 »). */
    #[ORM\Column(length: 80)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 80)]
    private string $slug = '';

    /** Premier jour de l'événement. */
    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $startsOn;

    /** Dernier jour de l'événement (inclus) ; null = une seule journée. */
    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $endsOn = null;

    #[ORM\Column(length: 150, nullable: true)]
    #[Assert\Length(max: 150)]
    private ?string $location = null;

    /** Date limite de réponse affichée aux adhérents (information, ne bloque rien). */
    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $applicationsDeadline = null;

    /** Texte d'accroche de la page d'accueil de la campagne. */
    #[ORM\Column(type: 'text', nullable: true)]
    #[Assert\Length(max: 2000)]
    private ?string $intro = null;

    #[ORM\Column]
    private bool $published = false;

    #[ORM\Column]
    private bool $applicationsOpen = true;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** @var Collection<int, VolunteerPole> */
    #[ORM\OneToMany(targetEntity: VolunteerPole::class, mappedBy: 'campaign', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    private Collection $poles;

    public function __construct()
    {
        $this->startsOn = new \DateTimeImmutable('today');
        $this->createdAt = new \DateTimeImmutable();
        $this->poles = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }

    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = trim($name); return $this; }

    public function getSlug(): string { return $this->slug; }
    public function setSlug(string $slug): self { $this->slug = trim($slug); return $this; }

    public function getStartsOn(): \DateTimeImmutable { return $this->startsOn; }
    public function setStartsOn(\DateTimeImmutable $d): self { $this->startsOn = $d; return $this; }

    public function getEndsOn(): ?\DateTimeImmutable { return $this->endsOn; }
    public function setEndsOn(?\DateTimeImmutable $d): self { $this->endsOn = $d; return $this; }

    public function getLocation(): ?string { return $this->location; }
    public function setLocation(?string $l): self { $this->location = $l !== null && trim($l) !== '' ? trim($l) : null; return $this; }

    public function getApplicationsDeadline(): ?\DateTimeImmutable { return $this->applicationsDeadline; }
    public function setApplicationsDeadline(?\DateTimeImmutable $d): self { $this->applicationsDeadline = $d; return $this; }

    public function getIntro(): ?string { return $this->intro; }
    public function setIntro(?string $intro): self { $this->intro = $intro !== null && trim($intro) !== '' ? trim($intro) : null; return $this; }

    public function isPublished(): bool { return $this->published; }
    public function setPublished(bool $p): self { $this->published = $p; return $this; }

    public function isApplicationsOpen(): bool { return $this->applicationsOpen; }
    public function setApplicationsOpen(bool $o): self { $this->applicationsOpen = $o; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    /** @return Collection<int, VolunteerPole> */
    public function getPoles(): Collection { return $this->poles; }

    public function addPole(VolunteerPole $pole): self
    {
        if (!$this->poles->contains($pole)) {
            $this->poles->add($pole);
        }
        return $this;
    }

    public function __toString(): string
    {
        return $this->name !== '' ? $this->name : '#'.$this->id;
    }
}
