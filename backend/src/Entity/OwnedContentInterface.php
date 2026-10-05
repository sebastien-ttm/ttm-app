<?php

namespace App\Entity;

/**
 * Contenu backend rattaché à celui qui l'a créé (auteur d'un article,
 * créateur d'un sondage, d'un événement ou d'une page). Sert à
 * ContentDeleteVoter : un éditeur ne supprime que ses propres contenus.
 */
interface OwnedContentInterface
{
    /** Null pour les contenus antérieurs à l'enregistrement du créateur. */
    public function getOwner(): ?User;
}
