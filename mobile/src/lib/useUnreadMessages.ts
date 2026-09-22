import { createContext, createElement, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react';
import { AppState, type AppStateStatus } from 'react-native';

import { auth } from '@/api/client';

/**
 * Compteur global des messages « non lus » (inbox à traiter + réponses
 * reçues non archivées) pour l'user connecté.
 *
 * Deux entrées :
 *  - le provider `UnreadMessagesProvider` : monté au niveau des tabs,
 *    fait le polling et expose la valeur + un `refresh()` déclencheur.
 *  - le consommateur `useUnreadMessages()` : appelable depuis n'importe
 *    quel écran enfant pour récupérer la valeur courante ou forcer un
 *    refresh après une action (archivage, réponse…).
 *
 * Rafraîchi :
 *  - au montage du provider (une fois),
 *  - toutes les 2 minutes en tâche de fond,
 *  - à chaque retour de background (AppState → 'active'),
 *  - à la demande via `refresh()`.
 *
 * `enabled` (côté provider) évite les 401 quand l'user n'est pas
 * encore authentifié au boot.
 */
type UnreadContextValue = {
  total: number;
  refresh: () => Promise<void>;
};

const UnreadMessagesContext = createContext<UnreadContextValue>({
  total: 0,
  refresh: async () => {},
});

export function UnreadMessagesProvider({ enabled, children }: { enabled: boolean; children: ReactNode }) {
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
    if (!enabled) {
      setTotal(0);
      return;
    }
    void refresh();

    const interval = setInterval(refresh, 120_000);
    const sub = AppState.addEventListener('change', (state: AppStateStatus) => {
      if (state === 'active') void refresh();
    });

    return () => {
      clearInterval(interval);
      sub.remove();
    };
  }, [enabled, refresh]);

  const value = useMemo(() => ({ total, refresh }), [total, refresh]);
  return createElement(UnreadMessagesContext.Provider, { value }, children);
}

export function useUnreadMessages(): UnreadContextValue {
  return useContext(UnreadMessagesContext);
}
