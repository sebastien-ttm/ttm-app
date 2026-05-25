<?php

namespace App\Entity\Trait;

use App\Enum\Profile;
use Doctrine\ORM\Mapping as ORM;

/**
 * Trait à inclure dans toute entité de contenu qui supporte un ciblage
 * d'audience (Article, TrainingSlot, StaticPage, Event, TrainingPlan, ...).
 *
 * Convention : audience vide [] = visible par tous.
 * Sinon, ne s'affiche qu'aux users avec au moins un profil en commun.
 */
trait AudienceAwareTrait
{
    /**
     * Liste de valeurs de Profile (jeune/senior/u25/parent/encadrant).
     * Vide = aucun ciblage (= tous).
     *
     * @var list<string>
     */
    #[ORM\Column(type: 'json', options: ['default' => '[]'])]
    private array $audience = [];

    /** @return list<string> */
    public function getAudience(): array
    {
        return array_values($this->audience);
    }

    /** @param list<string|Profile> $audience */
    public function setAudience(array $audience): self
    {
        $normalized = [];
        foreach ($audience as $a) {
            $value = $a instanceof Profile ? $a->value : (string) $a;
            if (Profile::tryFrom($value) !== null) {
                $normalized[$value] = true;
            }
        }
        $this->audience = array_keys($normalized);
        return $this;
    }
}
