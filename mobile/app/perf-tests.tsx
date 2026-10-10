import Ionicons from '@expo/vector-icons/Ionicons';
import { Stack } from 'expo-router';
import { useCallback, useEffect, useState } from 'react';
import { Pressable, RefreshControl, ScrollView, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { ApiError } from '@/api/client';
import { perfTests as perfTestsApi } from '@/api/resources';
import type {
  PerfTestGroup,
  PerfTestMineGroup,
  PerfTestSessionView,
  PerfTestsResponse,
} from '@/api/types';
import { useAuth } from '@/auth/AuthContext';
import { EmptyState, ErrorState, FullScreenLoading } from '@/components/Loading';
import { COLORS, RADIUS, SHADOWS, SPACING } from '@/config';
import { canSeeTraining } from '@/utils/profile';
import { fromIsoDate } from '@/utils/week';

/**
 * Tests chronométrés (onglet Entraînements) : pour une saison d'entraînement
 * (sept. → août), mes temps
 * et ceux de tous les adhérents, épreuve par épreuve puis séance par
 * séance. Les temps sont saisis par les entraîneurs côté backend.
 */
export default function PerfTestsScreen() {
  const { user } = useAuth();
  const canSee = canSeeTraining(user);

  const [season, setSeason] = useState<number | undefined>(undefined);
  const [data, setData] = useState<PerfTestsResponse | null>(null);
  // « Mon évolution » : mes temps sur toutes les saisons (indépendant de la saison affichée).
  const [mine, setMine] = useState<PerfTestMineGroup[]>([]);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState<string | null>(null);
  // Séances dépliées (« groupe:séance ») : la plus récente de chaque épreuve par défaut.
  const [open, setOpen] = useState<Set<string>>(new Set());

  const load = useCallback(async (s: number | undefined) => {
    try {
      setError(null);
      // « Mon évolution » est secondaire : son échec ne doit pas masquer le classement.
      const [resp, mineResp] = await Promise.all([
        perfTestsApi.list(s),
        perfTestsApi.mine().catch(() => null),
      ]);
      setData(resp);
      if (mineResp) setMine(mineResp.groups);
      setOpen(new Set(resp.groups.flatMap((g) => (g.sessions[0] ? [`${g.key}:${g.sessions[0].id}`] : []))));
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Erreur de chargement');
    }
  }, []);

  useEffect(() => {
    if (!canSee) return;
    let cancelled = false;
    setLoading(true);
    (async () => {
      await load(season);
      if (!cancelled) setLoading(false);
    })();
    return () => {
      cancelled = true;
    };
  }, [canSee, season, load]);

  const onRefresh = useCallback(async () => {
    setRefreshing(true);
    await load(season);
    setRefreshing(false);
  }, [load, season]);

  function toggle(key: string) {
    setOpen((prev) => {
      const next = new Set(prev);
      if (next.has(key)) next.delete(key);
      else next.add(key);
      return next;
    });
  }

  if (!canSee) {
    return (
      <SafeAreaView style={styles.root}>
        <Stack.Screen options={{ title: 'Tests chronométrés' }} />
        <EmptyState
          icon="🔒"
          title="Accès réservé"
          message="Cette page est réservée aux adhérents licenciés."
        />
      </SafeAreaView>
    );
  }

  return (
    <SafeAreaView style={styles.root} edges={['bottom']}>
      <Stack.Screen options={{ title: 'Tests chronométrés' }} />
      {loading ? (
        <FullScreenLoading />
      ) : error && !data ? (
        <ErrorState message={error} onRetry={() => load(season)} />
      ) : (
        <ScrollView
          contentContainerStyle={styles.content}
          refreshControl={<RefreshControl refreshing={refreshing} onRefresh={onRefresh} tintColor={COLORS.primary} />}
        >
          {mine.length > 0 && (
            <>
              <Text style={styles.sectionTitle}>📈 Mon évolution</Text>
              {mine.map((g) => (
                <EvolutionCard key={g.key} group={g} />
              ))}
              <Text style={styles.sectionTitle}>🏁 Classements par saison</Text>
            </>
          )}

          {data && (
            <>
              <Text style={styles.seasonTitle}>Saison {seasonLabel(data)}</Text>
              {data.seasons.length > 1 && (
                <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={styles.seasons}>
                  {data.seasons.map((s) => {
                    const active = s.year === data.season;
                    return (
                      <Pressable
                        key={s.year}
                        onPress={() => setSeason(s.year)}
                        style={[styles.seasonChip, active && styles.seasonChipActive]}
                        accessibilityRole="button"
                        accessibilityState={{ selected: active }}
                      >
                        <Text style={[styles.seasonLabel, active && styles.seasonLabelActive]}>{s.label}</Text>
                      </Pressable>
                    );
                  })}
                </ScrollView>
              )}
            </>
          )}

          {data && data.groups.length === 0 ? (
            <EmptyState
              icon="⏱️"
              title={`Aucun test chronométré sur la saison ${seasonLabel(data)}`}
              message="Les temps saisis par les entraîneurs apparaîtront ici."
            />
          ) : (
            data?.groups.map((g) => (
              <GroupCard key={g.key} group={g} open={open} onToggle={toggle} />
            ))
          )}
        </ScrollView>
      )}
    </SafeAreaView>
  );
}

/** Mes temps sur une épreuve, saison après saison : record, mini-graphique, liste avec écarts. */
function EvolutionCard({ group }: { group: PerfTestMineGroup }) {
  const [showAll, setShowAll] = useState(false);
  const results = group.results; // du plus ancien au plus récent
  const newestFirst = [...results].reverse();
  const visible = showAll ? newestFirst : newestFirst.slice(0, 4);

  const chart = results.slice(-8);
  const times = chart.map((r) => r.timeSeconds);
  const min = Math.min(...times);
  const max = Math.max(...times);

  return (
    <View style={styles.card}>
      <Text style={styles.cardTitle}>{group.icon} {group.label}</Text>

      <View style={styles.mineBox}>
        <Text style={styles.mineLabel}>🏅 Mon record</Text>
        <Text style={styles.mineTime}>{group.best.time}</Text>
        <Text style={styles.mineMeta}>
          saison {group.best.seasonLabel} · {results.length} test{results.length > 1 ? 's' : ''} au total
        </Text>
      </View>

      {chart.length > 1 && (
        <View>
          <View style={styles.chart}>
            {chart.map((r) => {
              const height = 14 + (max === min ? 18 : ((r.timeSeconds - min) / (max - min)) * 46);
              return (
                <View key={r.sessionId} style={styles.chartCol}>
                  <Text style={styles.chartTime} numberOfLines={1}>{r.time}</Text>
                  <View style={[styles.chartBar, { height }, r.isBest && styles.chartBarBest]} />
                  <Text style={styles.chartSeason}>{shortSeason(r.seasonLabel)}</Text>
                </View>
              );
            })}
          </View>
          <Text style={styles.chartHint}>Barre plus courte = temps plus rapide · en vert : mon record</Text>
        </View>
      )}

      {visible.map((r) => (
        <View key={r.sessionId} style={styles.evoRow}>
          <View style={{ flex: 1 }}>
            <Text style={styles.evoDate}>{formatDates(r.dates)}</Text>
            <Text style={styles.evoMeta}>
              {rankLabel(r.rank)} sur {r.participants} · saison {r.seasonLabel}
            </Text>
          </View>
          <Text style={[styles.evoTime, r.isBest && styles.evoTimeBest]}>{r.time}</Text>
          <Text
            style={[
              styles.evoDelta,
              r.deltaSeconds !== null && r.deltaSeconds < 0 && styles.evoDeltaBetter,
              r.deltaSeconds !== null && r.deltaSeconds > 0 && styles.evoDeltaWorse,
            ]}
          >
            {formatDelta(r.deltaSeconds)}
          </Text>
        </View>
      ))}

      {newestFirst.length > 4 && (
        <Pressable onPress={() => setShowAll((v) => !v)} style={({ pressed }) => [styles.showAll, pressed && { opacity: 0.7 }]}>
          <Text style={styles.showAllLabel}>
            {showAll ? 'Réduire' : `Voir tous mes temps (${newestFirst.length})`}
          </Text>
        </Pressable>
      )}
    </View>
  );
}

function GroupCard({
  group, open, onToggle,
}: {
  group: PerfTestGroup;
  open: Set<string>;
  onToggle: (key: string) => void;
}) {
  return (
    <View style={styles.card}>
      <Text style={styles.cardTitle}>{group.icon} {group.label}</Text>

      {group.mine ? (
        <View style={styles.mineBox}>
          <Text style={styles.mineLabel}>Mon meilleur temps</Text>
          <Text style={styles.mineTime}>{group.mine.best.time}</Text>
          <Text style={styles.mineMeta}>
            {group.mine.best.dates.length > 1 ? 'séance des ' : 'séance du '}{formatDates(group.mine.best.dates)} · {group.mine.count} test{group.mine.count > 1 ? 's' : ''} cette saison
          </Text>
        </View>
      ) : (
        <Text style={styles.noMine}>Vous n'avez pas de temps enregistré sur cette épreuve cette saison.</Text>
      )}

      {group.sessions.map((s) => {
        const key = `${group.key}:${s.id}`;
        return <SessionBlock key={key} session={s} isOpen={open.has(key)} onToggle={() => onToggle(key)} />;
      })}
    </View>
  );
}

function SessionBlock({
  session, isOpen, onToggle,
}: {
  session: PerfTestSessionView;
  isOpen: boolean;
  onToggle: () => void;
}) {
  const mine = session.results.find((r) => r.mine);
  return (
    <View style={styles.session}>
      <Pressable onPress={onToggle} style={({ pressed }) => [styles.sessionHeader, pressed && { opacity: 0.7 }]}>
        <View style={{ flex: 1 }}>
          <Text style={styles.sessionDate}>{formatDates(session.dates)}</Text>
          <Text style={styles.sessionMeta} numberOfLines={2}>
            {session.participants} participant{session.participants > 1 ? 's' : ''}
            {session.notes ? ` · ${session.notes}` : ''}
          </Text>
          {mine && (
            <Text style={styles.sessionMine}>
              Mon temps : {mine.time} · {rankLabel(mine.rank)} sur {session.participants}
            </Text>
          )}
        </View>
        <Ionicons name={isOpen ? 'chevron-up' : 'chevron-down'} size={18} color={COLORS.textMuted} />
      </Pressable>

      {isOpen && (
        <View>
          {session.results.map((r) => (
            <View key={r.userId} style={[styles.row, r.mine && styles.rowMine]}>
              <Text style={[styles.rank, r.mine && styles.rowMineText]}>{r.rank}</Text>
              <Text style={[styles.name, r.mine && styles.rowMineText]} numberOfLines={1}>
                {r.fullName}{r.mine ? ' (moi)' : ''}
              </Text>
              <Text style={[styles.time, r.mine && styles.rowMineText]}>{r.time}</Text>
            </View>
          ))}
        </View>
      )}
    </View>
  );
}

/** « 2025-2026 » → « 25-26 » (légende du graphique). */
function shortSeason(label: string): string {
  return label.replace(/^\d{2}(\d{2})-\d{2}(\d{2})$/, '$1-$2');
}

function formatSeconds(total: number): string {
  const h = Math.floor(total / 3600);
  const m = Math.floor((total % 3600) / 60);
  const s = total % 60;
  const ss = String(s).padStart(2, '0');
  return h > 0 ? `${h}:${String(m).padStart(2, '0')}:${ss}` : `${m}:${ss}`;
}

/** Écart avec le test précédent : « −12 s », « +1:05 », « = », « — » pour le premier test. */
function formatDelta(delta: number | null): string {
  if (delta === null) return '—';
  if (delta === 0) return '=';
  const abs = Math.abs(delta);
  return `${delta < 0 ? '−' : '+'}${abs < 60 ? `${abs} s` : formatSeconds(abs)}`;
}

function seasonLabel(data: PerfTestsResponse): string {
  return data.seasons.find((s) => s.year === data.season)?.label ?? String(data.season);
}

function joinFr(parts: string[]): string {
  return parts.length <= 1 ? (parts[0] ?? '') : `${parts.slice(0, -1).join(', ')} et ${parts[parts.length - 1]}`;
}

/**
 * Dates d'une séance, en français : « 12 mars 2026 », « 12 et 14 mars 2026 »,
 * « 28 février et 2 mars 2026 » (année et mois donnés une seule fois si communs).
 */
function formatDates(isos: string[]): string {
  const dates = isos.map(fromIsoDate);
  const day = (d: Date) => (d.getDate() === 1 ? '1er' : String(d.getDate()));
  const month = (d: Date) => d.toLocaleDateString('fr-FR', { month: 'long' });
  const sameYear = dates.every((d) => d.getFullYear() === dates[0].getFullYear());
  const sameMonth = sameYear && dates.every((d) => d.getMonth() === dates[0].getMonth());
  if (dates.length === 0) return '';
  if (!sameYear) return joinFr(dates.map((d) => `${day(d)} ${month(d)} ${d.getFullYear()}`));
  const year = dates[0].getFullYear();
  if (sameMonth) return `${joinFr(dates.map(day))} ${month(dates[0])} ${year}`;
  return `${joinFr(dates.map((d) => `${day(d)} ${month(d)}`))} ${year}`;
}

function rankLabel(rank: number): string {
  return rank === 1 ? '1er' : `${rank}e`;
}

const styles = StyleSheet.create({
  root: { flex: 1, backgroundColor: COLORS.background },
  content: { padding: SPACING.md, paddingBottom: SPACING.xxl, gap: SPACING.md },
  sectionTitle: { fontSize: 13, fontWeight: '700', color: COLORS.textMuted, textTransform: 'uppercase', letterSpacing: 0.5 },
  chart: { flexDirection: 'row', alignItems: 'flex-end', gap: 6, paddingTop: 4 },
  chartCol: { flex: 1, alignItems: 'center', justifyContent: 'flex-end', gap: 2 },
  chartTime: { fontSize: 10, color: COLORS.textMuted, fontVariant: ['tabular-nums'] },
  chartBar: { width: '70%', borderRadius: 4, backgroundColor: COLORS.secondary },
  chartBarBest: { backgroundColor: COLORS.success },
  chartSeason: { fontSize: 10, color: COLORS.textSubtle },
  chartHint: { fontSize: 11, color: COLORS.textSubtle, marginTop: 6 },
  evoRow: {
    flexDirection: 'row', alignItems: 'center', gap: SPACING.sm,
    paddingVertical: 8, borderTopWidth: StyleSheet.hairlineWidth, borderTopColor: COLORS.border,
  },
  evoDate: { fontSize: 14, fontWeight: '600', color: COLORS.text },
  evoMeta: { fontSize: 12, color: COLORS.textMuted },
  evoTime: { fontSize: 16, fontWeight: '700', color: COLORS.text, fontVariant: ['tabular-nums'] },
  evoTimeBest: { color: COLORS.success },
  evoDelta: { width: 62, textAlign: 'right', fontSize: 13, fontWeight: '600', color: COLORS.textMuted, fontVariant: ['tabular-nums'] },
  evoDeltaBetter: { color: COLORS.success },
  evoDeltaWorse: { color: COLORS.error },
  showAll: { alignItems: 'center', paddingVertical: 8 },
  showAllLabel: { fontSize: 14, fontWeight: '600', color: COLORS.secondaryDark },
  seasonTitle: { fontSize: 20, fontWeight: '800', color: COLORS.text },
  seasons: { gap: SPACING.sm, paddingVertical: 2 },
  seasonChip: {
    paddingHorizontal: 16, paddingVertical: 8,
    borderRadius: RADIUS.full, borderWidth: 1, borderColor: COLORS.border,
    backgroundColor: COLORS.surface,
  },
  seasonChipActive: { backgroundColor: COLORS.secondary, borderColor: COLORS.secondary },
  seasonLabel: { fontSize: 14, fontWeight: '600', color: COLORS.text },
  seasonLabelActive: { color: '#fff' },
  card: {
    backgroundColor: COLORS.surface, borderRadius: RADIUS.md,
    padding: SPACING.lg, gap: SPACING.md,
    ...SHADOWS.sm,
  },
  cardTitle: { fontSize: 18, fontWeight: '700', color: COLORS.text },
  mineBox: {
    backgroundColor: COLORS.secondarySoft, borderRadius: RADIUS.md,
    padding: SPACING.md, gap: 2,
  },
  mineLabel: { fontSize: 12, fontWeight: '700', color: COLORS.secondaryDark, textTransform: 'uppercase', letterSpacing: 0.5 },
  mineTime: { fontSize: 30, fontWeight: '800', color: COLORS.secondaryDark },
  mineMeta: { fontSize: 13, color: COLORS.secondaryDark },
  noMine: { fontSize: 14, color: COLORS.textMuted, fontStyle: 'italic' },
  session: { borderTopWidth: StyleSheet.hairlineWidth, borderTopColor: COLORS.border, paddingTop: SPACING.sm },
  sessionHeader: { flexDirection: 'row', alignItems: 'center', gap: SPACING.sm, paddingVertical: 4 },
  sessionDate: { fontSize: 15, fontWeight: '700', color: COLORS.text },
  sessionMeta: { fontSize: 13, color: COLORS.textMuted },
  sessionMine: { fontSize: 13, fontWeight: '600', color: COLORS.secondaryDark, marginTop: 2 },
  row: {
    flexDirection: 'row', alignItems: 'center', gap: SPACING.sm,
    paddingVertical: 7, paddingHorizontal: 6, borderRadius: RADIUS.sm,
  },
  rowMine: { backgroundColor: COLORS.secondarySoft },
  rowMineText: { fontWeight: '700', color: COLORS.secondaryDark },
  rank: { width: 28, fontSize: 14, color: COLORS.textMuted, textAlign: 'right' },
  name: { flex: 1, fontSize: 15, color: COLORS.text },
  time: { fontSize: 15, fontWeight: '600', color: COLORS.text, fontVariant: ['tabular-nums'] },
});
