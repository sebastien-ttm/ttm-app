import { useFocusEffect } from 'expo-router';
import { useCallback, useState } from 'react';

import { trips } from '@/api/resources';
import type { TripSummary } from '@/api/types';
import { useRefreshOnResume } from '@/lib/useRefreshOnResume';

import { ContextCardList } from './ContextParts';

/**
 * Accueil : mes déplacements (publiés, où je suis participant) à venir, en cours ou terminés depuis peu. Rien n'est
 * affiché sans déplacement. Un appui ouvre la vue dédiée : infos pratiques, événements, articles réservés, participants.
 */
export function MyTripsCard() {
  const [items, setItems] = useState<TripSummary[]>([]);

  const load = useCallback(() => {
    trips.mine()
      .then((r) => setItems(r.data))
      .catch(() => { /* pas de déplacement affiché si on ne peut pas vérifier */ });
  }, []);

  useFocusEffect(useCallback(() => { load(); }, [load]));
  useRefreshOnResume(load);

  return (
    <ContextCardList
      heading={`✈️ ${items.length > 1 ? 'Mes déplacements' : 'Mon déplacement'}`}
      items={items}
      hrefPrefix="/trip/"
      fallbackIcon="airplane"
      accessibilityKind="Déplacement"
    />
  );
}
