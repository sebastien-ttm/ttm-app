import { useMemo, useState } from 'react';
import { View } from 'react-native';

import { useAuth } from '@/auth/AuthContext';
import { EventSheets } from '@/components/gestion/EmargementTab';
import { MembersTab } from '@/components/gestion/MembersTab';
import { SubTabs } from '@/components/gestion/SubTabs';
import { canCheckIn, canManageCapsAndTimes, canSeeDirectory } from '@/utils/profile';

export type AdherentsView = 'list' | 'sheets';

/**
 * Onglet « Adhérents » de l'espace Staff, en deux vues sélectionnables sous les onglets :
 *  - Liste (entraîneur, encadrant, administrateur dont gestionnaire externe) : l'annuaire des adhérents, séniors et jeunes ensemble (nom, prénom, téléphone, appel
 *    ou message WhatsApp en un geste ; pour un jeune, fiche sanitaire et autorisation de rentrer seul) ;
 *  - Bonnets & événements : remise des bonnets du club (entraîneur, admin) et présence aux événements soumis au vote
 *    (entraîneur, encadrant, CoDir, admin).
 * Le sélecteur disparaît s'il n'y a qu'une vue (profil CoDir ou admin sans rôle de staff sportif).
 */
export function AdherentsTab({ initialView }: { initialView?: AdherentsView }) {
  const { user } = useAuth();
  const directory = canSeeDirectory(user);
  const manage = canManageCapsAndTimes(user);
  const checkIn = canCheckIn(user);

  const views = useMemo(() => {
    const list: { key: AdherentsView; label: string }[] = [];
    if (directory) list.push({ key: 'list', label: 'Liste' });
    if (checkIn) list.push({ key: 'sheets', label: manage ? 'Bonnets & événements' : 'Événements' });
    return list;
  }, [directory, checkIn, manage]);

  const [chosen, setChosen] = useState<AdherentsView | null>(initialView ?? null);
  const view = views.find((v) => v.key === chosen)?.key ?? views[0]?.key;

  return (
    <View style={{ flex: 1 }}>
      <SubTabs items={views} value={view ?? 'list'} onChange={setChosen} />
      <View style={{ flex: 1 }}>
        {view === 'list' ? <MembersTab /> : view === 'sheets' ? <EventSheets /> : null}
      </View>
    </View>
  );
}
