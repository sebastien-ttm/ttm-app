import Ionicons from '@expo/vector-icons/Ionicons';
import { useFocusEffect, useRouter } from 'expo-router';
import { useCallback, useState } from 'react';
import { Pressable, StyleSheet, Text, View } from 'react-native';

import { perfTests } from '@/api/resources';
import type { PerfTestAllure } from '@/api/types';
import { PaceBadge } from '@/components/groups/GroupFields';
import { COLORS, RADIUS, SPACING } from '@/config';

/**
 * Onglet Entraînement : dans quelle allure du club (1 à 5) je me situe, par épreuve, d'après mes temps des tests
 * chronométrés (meilleur temps de la saison la plus récente où je l'ai passée). Rien n'est affiché sans temps. Un appui
 * ouvre le récap de la saison.
 */
export function MyAllureCard() {
  const router = useRouter();
  const [allures, setAllures] = useState<PerfTestAllure[]>([]);

  useFocusEffect(useCallback(() => {
    let cancelled = false;
    perfTests.allures()
      .then((r) => { if (!cancelled) setAllures(r.data); })
      .catch(() => { /* sans les allures, l'écran s'affiche normalement */ });
    return () => { cancelled = true; };
  }, []));

  if (allures.length === 0) return null;

  return (
    <Pressable
      onPress={() => router.push('/perf-tests' as never)}
      accessibilityRole="button"
      accessibilityLabel="Mes allures : ouvrir le récap des tests chronométrés"
      style={({ pressed }) => [styles.card, pressed && { opacity: 0.75 }]}
    >
      <View style={styles.head}>
        <Text style={styles.title}>🏅 Mon allure</Text>
        <Ionicons name="chevron-forward" size={18} color={COLORS.textMuted} />
      </View>
      {allures.map((a) => (
        <View key={a.test} style={styles.row}>
          <Text style={styles.icon}>{a.icon}</Text>
          <View style={{ flex: 1, gap: 1 }}>
            <Text style={styles.label}>{a.shortLabel}</Text>
            <Text style={styles.sub} numberOfLines={2}>
              {a.time}{a.adjusted ? ` (bassin ${a.poolLength} m : −10 s → ${a.effectiveTime})` : ''} · saison {a.seasonLabel}
            </Text>
          </View>
          <PaceBadge level={a.level} speed={null} />
        </View>
      ))}
    </Pressable>
  );
}

const styles = StyleSheet.create({
  card: {
    gap: 8, padding: SPACING.md, marginTop: SPACING.sm, backgroundColor: COLORS.surface, borderRadius: RADIUS.md,
    borderLeftWidth: 4, borderLeftColor: COLORS.secondary, borderWidth: 1, borderColor: COLORS.border,
  },
  head: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between' },
  title: { fontSize: 15, fontWeight: '800', color: COLORS.text },
  row: { flexDirection: 'row', alignItems: 'center', gap: 10 },
  icon: { fontSize: 22 },
  label: { fontSize: 14, fontWeight: '700', color: COLORS.text },
  sub: { fontSize: 12, color: COLORS.textMuted },
});
