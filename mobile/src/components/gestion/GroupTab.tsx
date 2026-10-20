import { useFocusEffect } from 'expo-router';
import { useCallback, useState } from 'react';
import { View } from 'react-native';

import { ApiError } from '@/api/client';
import { trainingGroups } from '@/api/resources';
import type { TrainingGroupsMine } from '@/api/types';
import { ErrorState, FullScreenLoading } from '@/components/Loading';
import { GroupCreateForm } from '@/components/groups/GroupCreateForm';
import { GroupView } from '@/components/groups/GroupView';

/**
 * Onglet « Groupe » de l'espace Staff : mon groupe d'entraînement à la volée. Sans groupe actif, le formulaire de
 * création (avant une séance) ; avec un groupe, sa gestion complète — adhérents, encadrants, informations, trace GPX,
 * commentaires, position en direct. Un encadrant n'a qu'un groupe actif, valable jusqu'au prochain créneau.
 */
export function GroupTab() {
  const [mine, setMine] = useState<TrainingGroupsMine | null>(null);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    try {
      setError(null);
      setMine(await trainingGroups.mine());
    } catch (e) {
      setError(e instanceof ApiError ? e.message : 'Erreur de chargement');
    }
  }, []);

  useFocusEffect(useCallback(() => { void load(); }, [load]));

  if (mine === null) {
    return error ? <ErrorState message={error} onRetry={() => void load()} /> : <FullScreenLoading />;
  }

  const coached = mine.data.find((g) => g.isCoach);
  return (
    <View style={{ flex: 1 }}>
      {coached ? (
        <GroupView groupId={coached.id} onGone={() => void load()} />
      ) : (
        <GroupCreateForm preview={mine.preview} onCreated={() => void load()} />
      )}
    </View>
  );
}
