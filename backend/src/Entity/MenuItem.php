<?php

namespace App\Entity;

use App\Enum\MenuItemType;
use App\Repository\MenuItemRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: MenuItemRepository::class)]
#[ORM\Table(name: 'menu_item')]
class MenuItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 60)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 60)]
    private string $label;

    #[ORM\Column(length: 16, enumType: MenuItemType::class)]
    private MenuItemType $type = MenuItemType::Feed;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $target = null;

    #[ORM\Column(length: 60, nullable: true)]
    private ?string $icon = null;

    #[ORM\Column]
    private int $position = 0;

    #[ORM\Column]
    private bool $isVisible = true;

    public function getId(): ?int { return $this->id; }
    public function getLabel(): string { return $this->label; }
    public function setLabel(string $label): self { $this->label = $label; return $this; }
    public function getType(): MenuItemType { return $this->type; }
    public function setType(MenuItemType $type): self { $this->type = $type; return $this; }
    public function getTarget(): ?string { return $this->target; }
    public function setTarget(?string $target): self { $this->target = $target; return $this; }
    public function getIcon(): ?string { return $this->icon; }
    public function setIcon(?string $icon): self { $this->icon = $icon; return $this; }
    public function getPosition(): int { return $this->position; }
    public function setPosition(int $position): self { $this->position = $position; return $this; }
    public function isVisible(): bool { return $this->isVisible; }
    public function setIsVisible(bool $b): self { $this->isVisible = $b; return $this; }

    public function __toString(): string { return $this->label ?? '#'.$this->id; }
}
