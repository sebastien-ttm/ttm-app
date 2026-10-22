import { useState } from 'react';
import { Pressable, StyleSheet, Text, View } from 'react-native';

import type { StagePerfTest } from '@/api/types';
import { COLORS, RADIUS, SPACING } from '@/config';
import { tripDatesLabel } from '@/utils/trip';

const PREVIEW = 5;

/**
 * Prise de temps dédiée au stage : épreuve, période et classement (du plus rapide au plus lent), mon temps mis en
 * évidence. Les temps sont saisis par les entraîneurs ; sans temps, la carte l'annonce.
 */
export function StagePerfTestCard({ test }: { test: StagePerfTest }) {
  const [all, setAll] = useState(false);
  const rows = all ? test.results : test.results.slice(0, PREVIEW);
  const mine = test.results.find((r) => r.mine);

  return (
    <View style={styles.card}>
      <View style={styles.head}>
        <Text style={styles.icon}>{test.icon}</Text>
        <View style={{ flex: 1 }}>
          <Text style={styles.title}>{test.label}</Text>
          <Text style={styles.meta}>
            {tripDatesLabel({ startsOn: test.date, endsOn: test.endDate })}
            {test.notes ? ` · ${test.notes}` : ''}
          </Text>
        </View>
        {mine ? (
          <View style={styles.mineChip}>
            <Text style={styles.mineLabel}>Mon temps · {mine.time}</Text>
          </View>
        ) : null}
      </View>

      {test.results.length === 0 ? (
        <Text style={styles.empty}>Les temps seront saisis par les entraîneurs.</Text>
      ) : (
        <View style={styles.rows}>
          {rows.map((r, i) => (
            <View key={`${r.rank}-${r.fullName}-${i}`} style={[styles.row, r.mine && styles.rowMine]}>
              <Text style={[styles.rank, r.mine && styles.textMine]}>{r.rank}</Text>
              <Text style={[styles.name, r.mine && styles.textMine]} numberOfLines={1}>{r.fullName}</Text>
              <Text style={[styles.time, r.mine && styles.textMine]}>{r.time}</Text>
            </View>
          ))}
          {test.results.length > PREVIEW ? (
            <Pressable onPress={() => setAll((v) => !v)} accessibilityRole="button" style={styles.more}>
              <Text style={styles.moreLabel}>{all ? 'Réduire' : `Tout afficher (${test.results.length})`}</Text>
            </Pressable>
          ) : null}
        </View>
      )}
    </View>
  );
}

const styles = StyleSheet.create({
  card: {
    gap: SPACING.sm, padding: SPACING.md, backgroundColor: COLORS.surface,
    borderRadius: RADIUS.md, borderWidth: 1, borderColor: COLORS.border,
  },
  head: { flexDirection: 'row', alignItems: 'center', gap: 10 },
  icon: { fontSize: 24 },
  title: { fontSize: 15, fontWeight: '700', color: COLORS.text },
  meta: { fontSize: 12, color: COLORS.textMuted },
  mineChip: { paddingHorizontal: 8, paddingVertical: 3, borderRadius: 999, backgroundColor: COLORS.secondary },
  mineLabel: { fontSize: 11, fontWeight: '800', color: '#fff' },
  empty: { fontSize: 13, color: COLORS.textMuted },
  rows: { gap: 2 },
  row: { flexDirection: 'row', alignItems: 'center', gap: 10, paddingVertical: 5, paddingHorizontal: 6, borderRadius: 6 },
  rowMine: { backgroundColor: '#dbeafe' },
  rank: { width: 22, fontSize: 13, fontWeight: '800', color: COLORS.textMuted, textAlign: 'right' },
  name: { flex: 1, fontSize: 14, color: COLORS.text },
  time: { fontSize: 14, fontWeight: '700', color: COLORS.text, fontVariant: ['tabular-nums'] },
  textMine: { color: COLORS.secondaryDark, fontWeight: '800' },
  more: { alignSelf: 'flex-start', paddingVertical: 6, paddingHorizontal: 6 },
  moreLabel: { fontSize: 13, fontWeight: '700', color: COLORS.primary },
});
