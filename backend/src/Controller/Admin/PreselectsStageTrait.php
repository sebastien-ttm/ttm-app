<?php

namespace App\Controller\Admin;

use App\Entity\Stage;

/**
 * Pour les CRUD d'éléments pouvant être dédiés à un stage (article, événement, sondage, prise de temps) : le bouton
 * « ➕ … dédié » d'un stage ouvre le formulaire de création avec `?stageId=…`, et le nouvel élément lui est rattaché
 * d'office.
 */
trait PreselectsStageTrait
{
    private function requestedStage(): ?Stage
    {
        $id = $this->getContext()?->getRequest()->query->get('stageId');
        if ($id === null || !ctype_digit((string) $id)) {
            return null;
        }

        return $this->container->get('doctrine')->getRepository(Stage::class)->find((int) $id);
    }
}
