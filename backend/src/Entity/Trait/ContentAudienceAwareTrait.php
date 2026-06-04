<?php

namespace App\Entity\Trait;

use App\Enum\ContentAudience;
use Doctrine\ORM\Mapping as ORM;

/**
 * Trait complémentaire à AudienceAwareTrait : tagging du contenu (École de
 * Triathlon, etc.) — orthogonal à l'audience par profil.
 *
 * Convention : contentAudience vide [] = contenu non catégorisé (public).
 * Un user "Dirigeant" ne voit QUE les contenus avec contentAudience vide OU
 * contenant 'ecole_triathlon'.
 */
trait ContentAudienceAwareTrait
{
    /**
     * @var list<string>
     */
    #[ORM\Column(name: 'content_audience', type: 'json', options: ['default' => '[]'])]
    private array $contentAudience = [];

    /** @return list<string> */
    public function getContentAudience(): array
    {
        return array_values($this->contentAudience);
    }

    /** @param list<string|ContentAudience> $contentAudience */
    public function setContentAudience(array $contentAudience): self
    {
        $normalized = [];
        foreach ($contentAudience as $a) {
            $value = $a instanceof ContentAudience ? $a->value : (string) $a;
            if (ContentAudience::tryFrom($value) !== null) {
                $normalized[$value] = true;
            }
        }
        $this->contentAudience = array_keys($normalized);
        return $this;
    }

    public function hasContentTag(ContentAudience|string $tag): bool
    {
        $value = $tag instanceof ContentAudience ? $tag->value : (string) $tag;
        return in_array($value, $this->contentAudience, true);
    }
}
