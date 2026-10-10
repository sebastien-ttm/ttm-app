import Ionicons from '@expo/vector-icons/Ionicons';
import { Stack } from 'expo-router';
import { useCallback, useEffect, useState } from 'react';
import { Pressable, RefreshControl, ScrollView, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { ApiError } from '@/api/client';
import { perfTests as perfTestsApi } from '@/api/resources';
import type { PerfTestGroup, PerfTestSessionView, PerfTestsResponse } from '@/api/types';
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
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState<string | null>(null);
  // Séances dépliées (« groupe:séance ») : la plus récente de chaque épreuve par défaut.
  const [open, setOpen] = useState<Set<string>>(new Set());

  const load = useCallback(async (s: number | undefined) => {
    try {
      setError(null);
      const resp = await perfTestsApi.list(s);
      setData(resp);
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
            le {formatDay(group.mine.best.date)} · {group.mine.count} test{group.mine.count > 1 ? 's' : ''} cette saison
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
          <Text style={styles.sessionDate}>{formatDay(session.date)}</Text>
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

function seasonLabel(data: PerfTestsResponse): string {
  return data.seasons.find((s) => s.year === data.season)?.label ?? String(data.season);
}

function formatDay(iso: string): string {
  return fromIsoDate(iso).toLocaleDateString('fr-FR', { day: 'numeric', month: 'long', year: 'numeric' });
}

function rankLabel(rank: number): string {
  return rank === 1 ? '1er' : `${rank}e`;
}

const styles = StyleSheet.create({
  root: { flex: 1, backgroundColor: COLORS.background },
  content: { padding: SPACING.md, paddingBottom: SPACING.xxl, gap: SPACING.md },
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
