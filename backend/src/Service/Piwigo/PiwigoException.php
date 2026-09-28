<?php

namespace App\Service\Piwigo;

/**
 * Erreur de dialogue avec la galerie Piwigo (réseau, identifiants,
 * réponse « stat: fail »…). Le message est destiné aux logs ; les
 * contrôleurs renvoient un message générique à l'appli.
 */
class PiwigoException extends \RuntimeException
{
}
