import { useCallback, useEffect, useState } from 'react';
import { AppState, type AppStateStatus } from 'react-native';

import { auth } from '@/api/client';

/**
 * Compteur global des messages « non lus » (inbox à traiter + réponses
 * reçues non archivées) pour l'user connecté.
 *
 * Rafraîchi :
 *  - au montage (une fois),
 *  - à chaque retour de background (AppState → 'active'),
 *  - toutes les 2 minutes en tâche de fond.
 *
 * Le hook accepte un flag `enabled` : quand l'user n'est pas encore
 * authentifié, on évite les 401 inutiles au boot de l'app.
 */
export function useUnreadMessages(enabled: boolean = true): { total: number; refresh: () => Promise<void> } {
  const [total, setTotal] = useState(0);

  const refresh = useCallback(async () => {
    if (!enabled) return;
    try {
      const r = await auth.unreadMessagesCount();
      setTotal(r.total);
    } catch {
      // Silencieux : un badge qui ne se met pas à jour est moins grave
      // qu'une erreur remontée à l'user pour un chiffre secondaire.
    }
  }, [enabled]);

  useEffect(() => {
    if (!enabled) return;
    void refresh();

    // Polling léger (2 min) pour attraper les réponses arrivées pendant
    // que l'onglet Contact n'est pas ouvert.
    const interval = setInterval(refresh, 120_000);

    // Rafraîchissement immédiat au retour de background.
    const sub = AppState.addEventListener('change', (state: AppStateStatus) => {
      if (state === 'active') void refresh();
    });

    return () => {
      clearInterval(interval);
      sub.remove();
    };
  }, [enabled, refresh]);

  return { total, refresh };
}
