import { useFocusEffect, useRouter } from 'expo-router';
import { useCallback, useState } from 'react';
import { Pressable, StyleSheet, Text, View } from 'react-native';

import { perfTests } from '@/api/resources';
import type { PerfTestAllure } from '@/api/types';
import { PaceBadge } from '@/components/groups/GroupFields';
import { COLORS, RADIUS, SPACING } from '@/config';

/**
 * Onglet Entraînement, section « Mes allures » : dans quelle allure du club (1 à 5) je me situe, par sport, d'après mes
 * temps des tests chronométrés — l'allure seulement, pas les temps (ils sont dans « Mes temps et ceux du club »). Le
 * serveur ne renvoie que la dernière épreuve passée de chaque sport (pas un 1500 m et un 2000 m), avec ma meilleure
 * allure de la saison la plus récente où je l'ai passée. Sans temps, un message l'explique. Un appui ouvre mes temps.
 */
export function MyAllureCard() {
  const router = useRouter();
  const [allures, setAllures] = useState<PerfTestAllure[] | null>(null);

  useFocusEffect(useCallback(() => {
    let cancelled = false;
    perfTests.allures()
      .then((r) => { if (!cancelled) setAllures(r.data); })
      .catch(() => { if (!cancelled) setAllures((prev) => prev ?? []); });
    return () => { cancelled = true; };
  }, []));

  // Chargement en cours : rien (pas de message « aucune allure » qui clignote).
  if (allures === null) return null;

  if (allures.length === 0) {
    return (
      <View style={styles.card}>
        <Text style={styles.empty}>
          Pas encore d'allure : elle apparaît après votre première prise de temps (ou un temps déclaré et accepté).
        </Text>
      </View>
    );
  }

  return (
    <Pressable
      onPress={() => router.push('/perf-tests' as never)}
      accessibilityRole="button"
      accessibilityLabel="Mes allures : ouvrir mes temps et ceux du club"
      style={({ pressed }) => [styles.card, pressed && { opacity: 0.75 }]}
    >
      {allures.map((a) => (
        <View key={a.test} style={styles.row}>
          <Text style={styles.icon}>{a.icon}</Text>
          <View style={{ flex: 1, gap: 1 }}>
            <Text style={styles.label}>{a.shortLabel}</Text>
            <Text style={styles.sub}>saison {a.seasonLabel}</Text>
          </View>
          <PaceBadge level={a.level} speed={null} />
        </View>
      ))}
    </Pressable>
  );
}

const styles = StyleSheet.create({
  card: {
    gap: 8, padding: SPACING.md, backgroundColor: COLORS.surface, borderRadius: RADIUS.md,
    borderLeftWidth: 4, borderLeftColor: COLORS.secondary, borderWidth: 1, borderColor: COLORS.border,
  },
  row: { flexDirection: 'row', alignItems: 'center', gap: 10 },
  icon: { fontSize: 22 },
  label: { fontSize: 14, fontWeight: '700', color: COLORS.text },
  sub: { fontSize: 12, color: COLORS.textMuted },
  empty: { fontSize: 13, color: COLORS.textMuted, lineHeight: 18 },
});
