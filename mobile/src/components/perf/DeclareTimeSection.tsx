import { useFocusEffect } from 'expo-router';
import { useCallback, useState } from 'react';

import { perfTests } from '@/api/resources';
import type { PerfTestDeclaration } from '@/api/types';
import { useAuth } from '@/auth/AuthContext';
import { DeclareTimeBox } from '@/components/perf/DeclareTimeBox';
import { canSeeTraining } from '@/utils/profile';

/**
 * Onglet Entraînement, sous « Mes temps et ceux du club » : la possibilité de déclarer un temps pris individuellement
 * pour la saison en cours (validé ensuite par les entraîneurs), avec mes demandes et leur état. Réservé aux adhérents
 * licenciés, comme le reste des tests chronométrés ; visible même sans temps, c'est là qu'on en ajoute un.
 */
export function DeclareTimeSection() {
  const { user } = useAuth();
  const canDeclare = canSeeTraining(user);
  const [declarations, setDeclarations] = useState<PerfTestDeclaration[]>([]);

  const reload = useCallback(async () => {
    try {
      setDeclarations((await perfTests.declarations()).data);
    } catch {
      // Rafraîchissement de confort : on garde la liste affichée.
    }
  }, []);

  useFocusEffect(useCallback(() => {
    if (!canDeclare) return undefined;
    let cancelled = false;
    perfTests.declarations()
      .then((r) => { if (!cancelled) setDeclarations(r.data); })
      .catch(() => { /* sans la liste, le formulaire reste utilisable */ });
    return () => { cancelled = true; };
  }, [canDeclare]));

  if (!canDeclare) return null;
  return <DeclareTimeBox declarations={declarations} onChanged={reload} currentSeasonOnly />;
}
