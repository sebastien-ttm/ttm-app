import { createContext, createElement, useCallback, useContext, useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import { AppState, type AppStateStatus } from 'react-native';

import {
  bibs as bibsApi,
  marketplace as marketplaceApi,
  photos as photosApi,
  races as racesApi,
} from '@/api/resources';
import { useAuth } from '@/auth/AuthContext';
import { countUnseen, getLastSeenId, type SeenList } from '@/lib/seenListings';

/**
 * « Nouveautés non vues » des listes de l'onglet Social (albums photos, courses proposées, bourse aux
 * équipements, bourse aux dossards — voir lib/seenListings). Partagé entre l'onglet Social (pastilles des
 * cartes) et la barre d'onglets (pastille de l'icône Social, avec les messages non lus et les sondages sans
 * réponse).
 *
 * Même schéma provider/hook que useUnreadMessages : relu au montage, toutes les 5 minutes, au retour d'arrière-plan
 * et à la demande via `refresh()` (au retour sur l'onglet Social, après l'ouverture d'une liste). Silencieux en cas
 * d'échec réseau.
 */
export type SocialNewCounts = { photos: number; races: number; marketplace: number; bibs: number };

type SocialNewsContextValue = {
  counts: SocialNewCounts;
  /** Somme des quatre listes. */
  total: number;
  /** Bourse aux équipements / dossards ouvertes à ce compte (phase de test, voir MarketplaceAccess côté backend). */
  marketplaceEnabled: boolean;
  refresh: () => Promise<void>;
};

const NO_NEWS: SocialNewCounts = { photos: 0, races: 0, marketplace: 0, bibs: 0 };

const SocialNewsContext = createContext<SocialNewsContextValue>({
  counts: NO_NEWS,
  total: 0,
  marketplaceEnabled: false,
  refresh: async () => {},
});

export function SocialNewsProvider({ children }: { children: ReactNode }) {
  const { user } = useAuth();
  const userId = user?.id ?? null;
  const [counts, setCounts] = useState<SocialNewCounts>(NO_NEWS);
  const [marketplaceEnabled, setMarketplaceEnabled] = useState(false);

  // Un résultat arrivé après un changement de profil ne doit pas écraser celui du nouveau profil.
  const currentUserId = useRef<number | null>(userId);
  currentUserId.current = userId;

  useEffect(() => {
    let cancelled = false;
    setMarketplaceEnabled(false);
    if (userId === null) return;
    marketplaceApi.access()
      .then((r) => { if (!cancelled) setMarketplaceEnabled(r.enabled); })
      .catch(() => { /* pas d'entrée si on ne peut pas vérifier */ });
    return () => { cancelled = true; };
  }, [userId]);

  const refresh = useCallback(async () => {
    if (userId === null) return;
    const count = async (list: SeenList, fetchItems: () => Promise<{ id: number; authorId?: number }[]>) => {
      try {
        const [items, lastSeen] = await Promise.all([fetchItems(), getLastSeenId(list, userId)]);
        return countUnseen(items, lastSeen, userId);
      } catch {
        return 0;
      }
    };
    const [photos, races, marketplace, bibs] = await Promise.all([
      count('photos', () => photosApi.albums().then((r) => r.data)),
      count('races', () => racesApi.list().then((r) => r.data)),
      marketplaceEnabled ? count('marketplace', () => marketplaceApi.list().then((r) => r.data)) : Promise.resolve(0),
      marketplaceEnabled ? count('bibs', () => bibsApi.list().then((r) => r.data)) : Promise.resolve(0),
    ]);
    if (currentUserId.current === userId) setCounts({ photos, races, marketplace, bibs });
  }, [userId, marketplaceEnabled]);

  useEffect(() => {
    if (userId === null) {
      setCounts(NO_NEWS);
      return;
    }
    void refresh();

    const interval = setInterval(() => { void refresh(); }, 300_000);
    const sub = AppState.addEventListener('change', (state: AppStateStatus) => {
      if (state === 'active') void refresh();
    });

    return () => {
      clearInterval(interval);
      sub.remove();
    };
  }, [userId, refresh]);

  const value = useMemo(
    () => ({
      counts,
      total: counts.photos + counts.races + counts.marketplace + counts.bibs,
      marketplaceEnabled,
      refresh,
    }),
    [counts, marketplaceEnabled, refresh],
  );
  return createElement(SocialNewsContext.Provider, { value }, children);
}

export function useSocialNews(): SocialNewsContextValue {
  return useContext(SocialNewsContext);
}
