import Ionicons from '@expo/vector-icons/Ionicons';
import { useFocusEffect, useRouter } from 'expo-router';
import { useCallback, useState } from 'react';
import { Pressable, StyleSheet, Text, View } from 'react-native';

import { trainingGroups } from '@/api/resources';
import type { TrainingGroupSummary } from '@/api/types';
import { COLORS, RADIUS, SPACING } from '@/config';
import { useRefreshOnResume } from '@/lib/useRefreshOnResume';

function untilLabel(iso: string): string {
  return new Date(iso).toLocaleString('fr-FR', { weekday: 'long', day: 'numeric', month: 'long', hour: '2-digit', minute: '2-digit' });
}

/**
 * Onglet Entraînement : mes groupes d'entraînement actifs (ceux où un encadrant m'a ajouté, ou que j'encadre). Rien
 * n'est affiché sans groupe. Un appui ouvre le groupe : informations, trace GPX, commentaires, position des encadrants.
 */
export function MyGroupsCard() {
  const router = useRouter();
  const [groups, setGroups] = useState<TrainingGroupSummary[]>([]);

  const load = useCallback(() => {
    trainingGroups.mine()
      .then((r) => setGroups(r.data))
      .catch(() => { /* pas de groupe affiché si on ne peut pas vérifier */ });
  }, []);

  useFocusEffect(useCallback(() => { load(); }, [load]));
  useRefreshOnResume(load);

  if (groups.length === 0) return null;

  return (
    <View style={styles.section}>
      <Text style={styles.sectionTitle}>👥 {groups.length > 1 ? 'Mes groupes' : 'Mon groupe'}</Text>
      {groups.map((g) => (
        <Pressable
          key={g.id}
          onPress={() => router.push(('/training-group/' + g.id) as never)}
          accessibilityRole="button"
          style={({ pressed }) => [styles.card, pressed && { opacity: 0.75 }]}
        >
          <View style={styles.iconWrap}>
            <Ionicons name="people" size={22} color="#fff" />
          </View>
          <View style={{ flex: 1, gap: 2 }}>
            <Text style={styles.title} numberOfLines={1}>{g.name}</Text>
            <Text style={styles.sub} numberOfLines={2}>
              {g.isCoach ? 'Vous encadrez ce groupe' : `Encadré par ${g.coaches.map((c) => c.fullName).join(', ')}`}
              {` · ${g.memberCount} adhérent${g.memberCount > 1 ? 's' : ''}`}
            </Text>
            <Text style={styles.until}>Actif jusqu'au {untilLabel(g.expiresAt)}</Text>
          </View>
          <Ionicons name="chevron-forward" size={20} color={COLORS.textMuted} />
        </Pressable>
      ))}
    </View>
  );
}

const styles = StyleSheet.create({
  section: { gap: SPACING.sm, marginBottom: SPACING.lg },
  sectionTitle: { fontSize: 13, fontWeight: '800', color: COLORS.textMuted, textTransform: 'uppercase', letterSpacing: 0.4 },
  card: {
    flexDirection: 'row', alignItems: 'center', gap: 12, padding: SPACING.md,
    backgroundColor: COLORS.surface, borderRadius: RADIUS.md, borderLeftWidth: 4, borderLeftColor: COLORS.primary,
    borderWidth: 1, borderColor: COLORS.border,
  },
  iconWrap: { width: 42, height: 42, borderRadius: 21, backgroundColor: COLORS.primary, alignItems: 'center', justifyContent: 'center' },
  title: { fontSize: 16, fontWeight: '800', color: COLORS.text },
  sub: { fontSize: 12, color: COLORS.textMuted },
  until: { fontSize: 12, color: COLORS.secondaryDark, fontWeight: '600' },
});
