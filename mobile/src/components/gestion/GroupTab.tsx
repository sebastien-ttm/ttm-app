import { useFocusEffect } from 'expo-router';
import { useCallback, useState } from 'react';
import { Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';

import { ApiError } from '@/api/client';
import { trainingGroups } from '@/api/resources';
import type { TrainingGroupSummary, TrainingGroupsMine } from '@/api/types';
import { ErrorState, FullScreenLoading } from '@/components/Loading';
import { GroupCreateForm } from '@/components/groups/GroupCreateForm';
import { GroupView } from '@/components/groups/GroupView';
import { COLORS, SPACING } from '@/config';

function chipDate(g: TrainingGroupSummary): string {
  if (!g.sessionStartsAt) return '';
  const d = new Date(g.sessionStartsAt);
  return `${d.toLocaleDateString('fr-FR', { weekday: 'short', day: 'numeric' })} ${d.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })}`;
}

/**
 * Onglet « Groupe » de l'espace Staff : mes groupes d'entraînement. Chaque groupe est adossé à une séance de vélo ou de
 * course à pied ; un encadrant en a un par séance. Sans groupe, le formulaire de création ; avec un ou plusieurs, une
 * puce par groupe (et « Nouveau » pour en créer un autre sur une autre séance) puis la gestion complète du groupe choisi
 * — adhérents, encadrants, allure, places, informations, trace GPX, commentaires, position en direct.
 */
export function GroupTab() {
  const [mine, setMine] = useState<TrainingGroupsMine | null>(null);
  const [error, setError] = useState<string | null>(null);
  // Groupe affiché : son id, ou « new » (formulaire de création) ; null = le premier groupe.
  const [selected, setSelected] = useState<number | 'new' | null>(null);

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

  const coached = mine.data.filter((g) => g.isCoach);
  const current: number | 'new' = selected === 'new' || coached.length === 0
    ? 'new'
    : (coached.find((g) => g.id === selected)?.id ?? coached[0].id);

  return (
    <View style={{ flex: 1 }}>
      {coached.length > 0 && (
        <View style={styles.chipsBar}>
          <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={styles.chips}>
            {coached.map((g) => {
              const on = current === g.id;
              return (
                <Pressable
                  key={g.id}
                  onPress={() => setSelected(g.id)}
                  accessibilityRole="tab"
                  accessibilityState={{ selected: on }}
                  style={[styles.chip, on && styles.chipOn]}
                >
                  <Text style={[styles.chipTitle, on && styles.chipTitleOn]} numberOfLines={1}>{g.sportIcon ?? '👥'} {g.name}</Text>
                  {chipDate(g) !== '' && <Text style={[styles.chipSub, on && styles.chipTitleOn]}>{chipDate(g)}</Text>}
                </Pressable>
              );
            })}
            {mine.canCreate && (
              <Pressable
                onPress={() => setSelected('new')}
                accessibilityRole="tab"
                accessibilityState={{ selected: current === 'new' }}
                style={[styles.chip, styles.chipNew, current === 'new' && styles.chipOn]}
              >
                <Text style={[styles.chipTitle, current === 'new' && styles.chipTitleOn]}>＋ Nouveau</Text>
              </Pressable>
            )}
          </ScrollView>
        </View>
      )}

      {current === 'new' ? (
        <GroupCreateForm
          key="new"
          sessions={mine.sessions}
          onCreated={(g) => { setSelected(g.id); void load(); }}
          onCancel={coached.length > 0 ? () => setSelected(null) : undefined}
        />
      ) : (
        <GroupView key={current} groupId={current} onGone={() => { setSelected(null); void load(); }} />
      )}
    </View>
  );
}

const styles = StyleSheet.create({
  chipsBar: { borderBottomWidth: StyleSheet.hairlineWidth, borderBottomColor: COLORS.border, backgroundColor: COLORS.surface },
  chips: { gap: 8, paddingHorizontal: SPACING.md, paddingVertical: 8 },
  chip: {
    maxWidth: 220, paddingHorizontal: 12, paddingVertical: 7, borderRadius: 14,
    backgroundColor: COLORS.surfaceAlt, borderWidth: 1, borderColor: COLORS.border,
  },
  chipOn: { backgroundColor: COLORS.primary, borderColor: COLORS.primary },
  chipNew: { borderStyle: 'dashed', justifyContent: 'center' },
  chipTitle: { fontSize: 13, fontWeight: '700', color: COLORS.text },
  chipTitleOn: { color: '#fff' },
  chipSub: { fontSize: 11, color: COLORS.textMuted },
});
