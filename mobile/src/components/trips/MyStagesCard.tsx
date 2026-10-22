import { useFocusEffect } from 'expo-router';
import { useCallback, useState } from 'react';

import { stages } from '@/api/resources';
import type { StageSummary } from '@/api/types';
import { useRefreshOnResume } from '@/lib/useRefreshOnResume';

import { ContextCardList } from './ContextParts';

/**
 * Accueil : mes stages (publiés, où je suis participant) à venir, en cours ou terminés depuis peu. Rien n'est affiché
 * sans stage. Un appui ouvre la vue dédiée : entraînements, événements, sondages, prises de temps et articles du stage.
 */
export function MyStagesCard() {
  const [items, setItems] = useState<StageSummary[]>([]);

  const load = useCallback(() => {
    stages.mine()
      .then((r) => setItems(r.data))
      .catch(() => { /* pas de stage affiché si on ne peut pas vérifier */ });
  }, []);

  useFocusEffect(useCallback(() => { load(); }, [load]));
  useRefreshOnResume(load);

  return (
    <ContextCardList
      heading={`🏕️ ${items.length > 1 ? 'Mes stages' : 'Mon stage'}`}
      items={items}
      hrefPrefix="/stage/"
      fallbackIcon="bonfire"
      accessibilityKind="Stage"
    />
  );
}
