<?php

namespace App\Controller\Api;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Réponse « état en direct » des écrans du Staff à plusieurs utilisateurs (émargement,
 * bonnets, saisie des temps). L'appli redemande l'état toutes les quelques secondes ;
 * `version` est une empreinte de l'état : si le client la renvoie (`?v=…`) et que rien n'a
 * changé, la réponse se résume à `{unchanged: true}`, ce qui rend l'actualisation très légère
 * pour le serveur et pour le téléphone.
 *
 * À utiliser dans un contrôleur qui étend AbstractController.
 */
trait StaffLiveStateTrait
{
    /** @param array<string, mixed> $state état complet, dans un ordre stable (trié par identifiant) */
    private function liveState(Request $request, array $state): JsonResponse
    {
        $version = substr(md5((string) json_encode($state)), 0, 16);
        $payload = $request->query->get('v') === $version
            ? ['version' => $version, 'unchanged' => true]
            : ['version' => $version] + $state;

        $response = new JsonResponse($payload);
        // Toujours l'état du moment : jamais servi depuis un cache.
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
