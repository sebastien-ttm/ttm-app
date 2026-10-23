import Ionicons from '@expo/vector-icons/Ionicons';
import { useEffect, useState } from 'react';
import { Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';

import type { PerfTestRecap, PerfTestRecapRow, PerfTestRecapSession } from '@/api/types';
import { PaceBadge } from '@/components/groups/GroupFields';
import { COLORS, RADIUS, SHADOWS, SPACING } from '@/config';
import { fromIsoDate } from '@/utils/week';

function dayLabel(iso: string, withYear = false): string {
  return fromIsoDate(iso).toLocaleDateString('fr-FR', { day: 'numeric', month: 'short', ...(withYear ? { year: 'numeric' } : {}) });
}

/** « 12 oct. 2025 » ou « 12 → 14 oct. 2025 », suivi des notes de la prise de temps (lieu, groupe testé…). */
export function sessionLabel(session: PerfTestRecapSession): string {
  const when = session.endDate && session.endDate !== session.date
    ? `${dayLabel(session.date)} → ${dayLabel(session.endDate, true)}`
    : dayLabel(session.date, true);
  return session.notes ? `${when} · ${session.notes}` : when;
}

/**
 * Récap d'une saison par épreuve : les adhérents classés dans une allure de 1 à 5 d'après leur meilleur temps de la
 * saison, avec la séance où il a été réalisé. Natation : un temps en bassin de 50 m est diminué de 10 s pour le
 * classement — le temps réel et la mention sont affichés. Mon allure est mise en évidence.
 */
export function SeasonRecap({ recaps, seasonLabel }: { recaps: PerfTestRecap[]; seasonLabel: string }) {
  const [test, setTest] = useState<string>(recaps[0]?.test ?? '');
  const recap = recaps.find((r) => r.test === test) ?? recaps[0];
  // Allures dépliées : la mienne, à défaut la première qui a du monde.
  const [open, setOpen] = useState<Set<number>>(new Set());

  useEffect(() => {
    if (!recap) return;
    const first = recap.mine?.level ?? recap.intervals.find((iv) => recap.rows.some((r) => r.level === iv.level))?.level;
    setOpen(new Set(first !== undefined ? [first] : []));
  }, [recap?.test, recap?.mine?.level]);

  if (!recap) return null;

  function toggle(level: number) {
    setOpen((prev) => {
      const next = new Set(prev);
      if (next.has(level)) next.delete(level);
      else next.add(level);
      return next;
    });
  }

  return (
    <View style={{ gap: SPACING.md }}>
      <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={styles.tabs}>
        {recaps.map((r) => {
          const active = r.test === recap.test;
          return (
            <Pressable
              key={r.test}
              onPress={() => setTest(r.test)}
              accessibilityRole="tab"
              accessibilityState={{ selected: active }}
              style={[styles.tabChip, active && styles.tabChipActive]}
            >
              <Text style={[styles.tabLabel, active && styles.tabLabelActive]}>{r.icon} {r.shortLabel}</Text>
            </Pressable>
          );
        })}
      </ScrollView>

      <View style={styles.card}>
        <Text style={styles.cardTitle}>{recap.icon} {recap.label} · saison {seasonLabel}</Text>

        {recap.mine ? (
          <View style={styles.mine}>
            <PaceBadge level={recap.mine.level} speed={null} />
            <Text style={styles.mineText}>
              Votre allure : {recap.mine.time}
              {recap.mine.adjusted ? ` (retenu ${recap.mine.effectiveTime}, bassin 50 m)` : ''}
            </Text>
          </View>
        ) : (
          <Text style={styles.muted}>Vous n'avez pas de temps sur cette épreuve cette saison : pas d'allure.</Text>
        )}

        <View style={styles.legend}>
          {recap.intervals.map((iv) => (
            <Text key={iv.level} style={styles.legendRow}><Text style={styles.legendLevel}>Allure {iv.level}</Text> · {iv.label}</Text>
          ))}
        </View>
        {recap.adjustmentNote && <Text style={styles.note}>⚠️ {recap.adjustmentNote}</Text>}
      </View>

      {recap.rows.length === 0 ? (
        <Text style={styles.muted}>Aucun temps sur cette épreuve pour la saison {seasonLabel}.</Text>
      ) : (
        recap.intervals.map((iv) => {
          const rows = recap.rows.filter((r) => r.level === iv.level);
          const expanded = open.has(iv.level);
          const mineHere = recap.mine?.level === iv.level;
          return (
            <View key={iv.level} style={[styles.level, mineHere && styles.levelMine]}>
              <Pressable
                onPress={() => toggle(iv.level)}
                disabled={rows.length === 0}
                accessibilityRole="button"
                accessibilityState={{ expanded }}
                style={({ pressed }) => [styles.levelHead, pressed && { opacity: 0.8 }]}
              >
                <PaceBadge level={iv.level} speed={null} />
                <View style={{ flex: 1 }}>
                  <Text style={styles.levelInterval}>{iv.label}</Text>
                </View>
                <Text style={styles.levelCount}>{rows.length} adhérent{rows.length > 1 ? 's' : ''}</Text>
                {rows.length > 0 && <Ionicons name={expanded ? 'chevron-up' : 'chevron-down'} size={18} color={COLORS.textMuted} />}
              </Pressable>
              {expanded && rows.map((r, i) => <RecapRow key={`${r.userId ?? r.fullName}-${i}`} row={r} />)}
            </View>
          );
        })
      )}
    </View>
  );
}

function RecapRow({ row }: { row: PerfTestRecapRow }) {
  return (
    <View style={[styles.row, row.mine && styles.rowMine]}>
      <View style={{ flex: 1, gap: 1 }}>
        <Text style={[styles.name, row.mine && styles.textMine]} numberOfLines={1}>{row.fullName}{row.mine ? ' (vous)' : ''}</Text>
        <Text style={styles.session} numberOfLines={2}>Séance : {sessionLabel(row.session)}</Text>
      </View>
      <View style={{ alignItems: 'flex-end' }}>
        <Text style={[styles.time, row.mine && styles.textMine]}>{row.time}</Text>
        {row.adjusted && <Text style={styles.adjusted}>bassin {row.poolLength} m : −10 s → {row.effectiveTime}</Text>}
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  tabs: { gap: 8, paddingVertical: 2 },
  tabChip: { paddingHorizontal: 14, paddingVertical: 8, borderRadius: 999, backgroundColor: COLORS.surface, borderWidth: 1, borderColor: COLORS.border },
  tabChipActive: { backgroundColor: COLORS.primary, borderColor: COLORS.primary },
  tabLabel: { fontSize: 13, fontWeight: '700', color: COLORS.text },
  tabLabelActive: { color: '#fff' },
  card: { backgroundColor: COLORS.surface, borderRadius: RADIUS.md, padding: SPACING.md, gap: SPACING.sm, ...SHADOWS.sm },
  cardTitle: { fontSize: 15, fontWeight: '800', color: COLORS.text },
  mine: { flexDirection: 'row', alignItems: 'center', gap: 10, flexWrap: 'wrap' },
  mineText: { fontSize: 14, fontWeight: '700', color: COLORS.secondaryDark },
  muted: { fontSize: 13, color: COLORS.textMuted, lineHeight: 18 },
  legend: { gap: 2, marginTop: 2 },
  legendRow: { fontSize: 12, color: COLORS.textMuted },
  legendLevel: { fontWeight: '800', color: COLORS.text },
  note: { fontSize: 12, color: '#b45309', lineHeight: 17 },
  level: { backgroundColor: COLORS.surface, borderRadius: RADIUS.md, borderWidth: 1, borderColor: COLORS.border, overflow: 'hidden' },
  levelMine: { borderColor: COLORS.secondary, borderWidth: 2 },
  levelHead: { flexDirection: 'row', alignItems: 'center', gap: 10, padding: SPACING.md },
  levelInterval: { fontSize: 13, color: COLORS.text, fontWeight: '600' },
  levelCount: { fontSize: 12, color: COLORS.textMuted },
  row: {
    flexDirection: 'row', alignItems: 'center', gap: 10, paddingHorizontal: SPACING.md, paddingVertical: 9,
    borderTopWidth: StyleSheet.hairlineWidth, borderTopColor: COLORS.border,
  },
  rowMine: { backgroundColor: '#dbeafe' },
  name: { fontSize: 14, fontWeight: '600', color: COLORS.text },
  session: { fontSize: 12, color: COLORS.textMuted },
  time: { fontSize: 15, fontWeight: '800', color: COLORS.text, fontVariant: ['tabular-nums'] },
  adjusted: { fontSize: 11, color: '#b45309' },
  textMine: { color: COLORS.secondaryDark },
});
