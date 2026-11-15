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
 * Onglet « Groupes » de l'espace Staff : les groupes d'entraînement que j'encadre, seuls à y figurer (l'onglet
 * Entraînements ne montre que ceux où je suis adhérent). Chaque groupe est adossé à une séance ; un encadrant en a un par
 * séance. Une puce par groupe : les miens d'abord, en couleur et marqués « Mon groupe » ; les groupes des autres
 * encadrants sur mes séances ensuite, en retrait et en lecture seule (« Groupe de … »). « Nouveau » crée le mien sur une
 * autre séance ; sans groupe, le formulaire de création s'ouvre directement. Le groupe choisi s'ouvre dessous : adhérents,
 * encadrants, allure, places, informations, trace GPX, commentaires, position en direct.
 */
export function GroupTab() {
  const [mine, setMine] = useState<TrainingGroupsMine | null>(null);
  const [error, setError] = useState<string | null>(null);
  // Groupe affiché : son id, ou « new » (formulaire de création) ; null = le premier de mes groupes.
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
  const others = mine.others ?? [];
  const current: number | 'new' = selected === 'new' || coached.length === 0
    ? 'new'
    : ([...coached, ...others].find((g) => g.id === selected)?.id ?? coached[0].id);

  function chip(g: TrainingGroupSummary, isMine: boolean) {
    const on = current === g.id;
    const date = chipDate(g);
    const owner = isMine ? 'Mon groupe' : `Groupe de ${g.coaches.map((c) => c.fullName).join(', ')}`;
    return (
      <Pressable
        key={g.id}
        onPress={() => setSelected(g.id)}
        accessibilityRole="tab"
        accessibilityState={{ selected: on }}
        accessibilityLabel={`${g.name}, ${owner}`}
        style={[styles.chip, isMine ? styles.chipMine : styles.chipOther, on && (isMine ? styles.chipOn : styles.chipOtherOn)]}
      >
        <Text style={[styles.chipTitle, !isMine && styles.chipTitleOther, on && styles.chipTitleOn]} numberOfLines={1}>
          {g.sportIcon ?? '👥'} {g.name}
        </Text>
        <Text style={[styles.chipSub, isMine && styles.chipSubMine, on && styles.chipTitleOn]} numberOfLines={1}>
          {date !== '' ? `${owner} · ${date}` : owner}
        </Text>
      </Pressable>
    );
  }

  return (
    <View style={{ flex: 1 }}>
      {coached.length > 0 && (
        <View style={styles.chipsBar}>
          <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={styles.chips}>
            {coached.map((g) => chip(g, true))}
            {others.map((g) => chip(g, false))}
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
    maxWidth: 240, paddingHorizontal: 12, paddingVertical: 7, borderRadius: 14,
    backgroundColor: COLORS.surfaceAlt, borderWidth: 1, borderColor: COLORS.border,
  },
  // Mon groupe : cadre et fond de la couleur du club, plein une fois choisi.
  chipMine: { backgroundColor: COLORS.primarySoft, borderColor: COLORS.primary, borderWidth: 1.5 },
  chipOn: { backgroundColor: COLORS.primary, borderColor: COLORS.primary },
  // Groupe d'un autre encadrant : neutre, en pointillés, d'une autre couleur une fois choisi.
  chipOther: { borderStyle: 'dashed' },
  chipOtherOn: { backgroundColor: COLORS.secondary, borderColor: COLORS.secondary },
  chipNew: { borderStyle: 'dashed', justifyContent: 'center' },
  chipTitle: { fontSize: 13, fontWeight: '700', color: COLORS.text },
  chipTitleOther: { fontWeight: '600', color: COLORS.textMuted },
  chipTitleOn: { color: '#fff' },
  chipSub: { fontSize: 11, color: COLORS.textMuted },
  chipSubMine: { fontWeight: '700', color: COLORS.primary },
});
