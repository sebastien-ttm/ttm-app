<?php

namespace App\Entity;

use App\Repository\EventTagRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Tag configurable pour les événements du calendrier.
 * Remplace l'ancien EventType enum (case list codée en dur) par une
 * liste dynamique gérée depuis l'admin : chaque tag porte son libellé
 * et sa couleur, peut être activé/désactivé et réordonné.
 */
#[ORM\Entity(repositoryClass: EventTagRepository::class)]
#[ORM\Table(name: 'event_tag')]
class EventTag
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 60)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 60)]
    private string $name = '';

    /**
     * Couleur d'affichage (hex #RRGGBB). Utilisée comme fond des
     * pastilles date-box + chips côté mobile, et comme couleur du
     * badge côté admin.
     */
    #[ORM\Column(length: 9)]
    #[Assert\NotBlank]
    #[Assert\Regex(pattern: '/^#[0-9a-fA-F]{6}([0-9a-fA-F]{2})?$/', message: 'Format attendu : #RRGGBB')]
    private string $color = '#607D8B';

    /**
     * Ordre d'affichage dans le sélecteur admin + les listings.
     * Plus petit = plus haut / plus tôt. Défaut 100 pour laisser de la
     * place à des insertions ultérieures.
     */
    #[ORM\Column(options: ['default' => 100])]
    private int $position = 100;

    /**
     * Tag masqué : n'apparaît plus dans le sélecteur admin ni côté
     * mobile. Les événements qui le référencent le gardent (choix
     * délibéré : on ne rompt pas un affichage historique tant que
     * l'admin n'a pas décidé de supprimer le tag).
     */
    #[ORM\Column(options: ['default' => true])]
    private bool $active = true;

    public function getId(): ?int { return $this->id; }

    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = trim($name); return $this; }

    public function getColor(): string { return $this->color; }
    public function setColor(string $color): self { $this->color = $color; return $this; }

    public function getPosition(): int { return $this->position; }
    public function setPosition(int $position): self { $this->position = $position; return $this; }

    public function isActive(): bool { return $this->active; }
    public function setActive(bool $active): self { $this->active = $active; return $this; }

    public function __toString(): string { return $this->name !== '' ? $this->name : '#'.$this->id; }
}
