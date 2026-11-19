import { useFocusEffect } from 'expo-router';
import { useCallback, useMemo, useState } from 'react';
import { View } from 'react-native';

import { minibus as minibusApi } from '@/api/resources';
import { useAuth } from '@/auth/AuthContext';
import { BoardTab } from '@/components/gestion/BoardTab';
import { MinibusTab } from '@/components/gestion/MinibusTab';
import { SubTabs } from '@/components/gestion/SubTabs';
import { canUseMinibus, isMinibusManager } from '@/utils/profile';

export type CodirView = 'reunions' | 'minibus';

/**
 * Onglet « Comité directeur » de l'espace Staff : deux sous-parties sélectionnables sous les onglets :
 *  - Réunions : actions en cours, réunions et ordre du jour de chacune, comptes rendus (membres du CoDir, administrateurs,
 *    entraîneurs) ;
 *  - Emprunt minibus : calendrier du minibus du club, demande de réservation, charte, états des lieux (membres du CoDir
 *    seulement, pour le moment). Le gestionnaire du minibus voit en pastille le nombre de demandes à valider.
 * Le sélecteur disparaît quand il n'y a qu'une sous-partie à proposer (ex. un entraîneur sans rôle CoDir).
 */
export function CodirTab({ initialView }: { initialView?: CodirView }) {
  const { user } = useAuth();
  const minibus = canUseMinibus(user);
  const manager = isMinibusManager(user);
  const [pending, setPending] = useState(0);

  // Pastille du gestionnaire : demandes en attente (relue à chaque retour sur l'écran ; discret en cas d'erreur).
  useFocusEffect(useCallback(() => {
    if (!manager) return;
    let alive = true;
    minibusApi.list().then((r) => { if (alive) setPending(r.pendingCount ?? 0); }).catch(() => undefined);
    return () => { alive = false; };
  }, [manager]));

  const views = useMemo(() => {
    const list: { key: CodirView; label: string; badge?: number }[] = [{ key: 'reunions', label: 'Réunions' }];
    if (minibus) list.push({ key: 'minibus', label: 'Emprunt minibus', badge: pending });
    return list;
  }, [minibus, pending]);

  const [chosen, setChosen] = useState<CodirView | null>(initialView ?? null);
  // La vue demandée (ou choisie) doit être permise ; sinon la première.
  const view = views.find((v) => v.key === chosen)?.key ?? 'reunions';

  return (
    <View style={{ flex: 1 }}>
      <SubTabs items={views} value={view} onChange={setChosen} />
      <View style={{ flex: 1 }}>
        {view === 'minibus' ? <MinibusTab onPendingChange={setPending} /> : <BoardTab />}
      </View>
    </View>
  );
}
