<?php

use App\Kernel;

// Force le fuseau France pour toutes les manipulations de dates PHP
// (création, hydration Doctrine des DATETIME naïfs, sérialisation ATOM
// avec offset correct). Sinon l'app tourne sous le fuseau système du
// serveur (souvent UTC), et les articles/pages s'affichent en GMT côté
// mobile au lieu de l'heure française.
date_default_timezone_set('Europe/Paris');

require_once dirname(__DIR__).'/vendor/autoload_runtime.php';

return function (array $context) {
    return new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
};
