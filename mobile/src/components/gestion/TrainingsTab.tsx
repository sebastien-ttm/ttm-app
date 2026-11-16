import { useMemo, useState } from 'react';
import { View } from 'react-native';

import { useAuth } from '@/auth/AuthContext';
import { GroupTab } from '@/components/gestion/GroupTab';
import { PresenceTab } from '@/components/gestion/PresenceTab';
import { TrainingSheets } from '@/components/gestion/EmargementTab';
import { SubTabs } from '@/components/gestion/SubTabs';
import { canCheckIn, canManageCapsAndTimes, isStaffMember } from '@/utils/profile';

export type TrainingsView = 'presences' | 'groupes' | 'sheets';

/**
 * Onglet « Entraînements » de l'espace Staff : tout ce qui touche aux séances d'entraînement, en trois vues
 * sélectionnables sous les onglets :
 *  - Présences (entraîneur, encadrant) : indiquer / confirmer ses présences, semaine type ;
 *  - Groupes (entraîneur, encadrant) : mon groupe d'entraînement à la volée, créé avant une séance ;
 *  - Émargement & tests : présence aux entraînements sur inscription (entraîneur, encadrant, CoDir, admin) et saisie des
 *    temps des tests chronométrés (entraîneur, admin).
 * Chaque vue reste filtrée selon le profil ; le sélecteur disparaît s'il n'y en a qu'une.
 */
export function TrainingsTab({ initialView }: { initialView?: TrainingsView }) {
  const { user } = useAuth();
  const staff = isStaffMember(user);
  const manage = canManageCapsAndTimes(user);
  const checkIn = canCheckIn(user);

  const views = useMemo(() => {
    const list: { key: TrainingsView; label: string }[] = [];
    if (staff) {
      list.push({ key: 'presences', label: 'Présences' });
      list.push({ key: 'groupes', label: 'Groupes' });
    }
    if (checkIn) list.push({ key: 'sheets', label: manage ? 'Émargement & tests' : 'Émargement' });
    return list;
  }, [staff, checkIn, manage]);

  const [chosen, setChosen] = useState<TrainingsView | null>(initialView ?? null);
  // La vue demandée (ou choisie) doit être permise ; sinon la première accessible.
  const view = views.find((v) => v.key === chosen)?.key ?? views[0]?.key;

  return (
    <View style={{ flex: 1 }}>
      <SubTabs items={views} value={view ?? 'presences'} onChange={setChosen} />
      <View style={{ flex: 1 }}>
        {view === 'presences' ? <PresenceTab />
          : view === 'groupes' ? <GroupTab />
          : view === 'sheets' ? <TrainingSheets />
          : null}
      </View>
    </View>
  );
}
