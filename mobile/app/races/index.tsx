import Ionicons from '@expo/vector-icons/Ionicons';
import { Stack, useFocusEffect, useRouter } from 'expo-router';
import { useCallback, useMemo, useState } from 'react';
import { Alert, FlatList, Platform, Pressable, RefreshControl, StyleSheet, Text, View } from 'react-native';

import { ApiError } from '@/api/client';
import { races as racesApi } from '@/api/resources';
import type { RaceProposal, RaceType, RaceVote } from '@/api/types';
import { useAuth } from '@/auth/AuthContext';
import { todayIso } from '@/components/DateField';
import { ErrorState } from '@/components/Loading';
import { formatRaceDate, formatRaceDates, RACE_TYPES, raceTypeMeta } from '@/components/RaceForm';
import { isRacePublished, raceStatus, RaceStatusBadge } from '@/components/RaceStatus';
import { COLORS, RADIUS, SPACING } from '@/config';
import { useRefreshOnResume } from '@/lib/useRefreshOnResume';
import { markSeen, seenMark } from '@/lib/seenListings';

type Tab = 'browse' | 'mine';
/** Ligne de la liste : une carte, l'intitulé d'un bloc (mes réponses / les autres compétitions) ou celui d'un mois. */
type Row =
  | { kind: 'header'; key: string; title: string }
  | { kind: 'month'; key: string; title: string }
  | { kind: 'race'; key: string; item: RaceProposal };

/** Une proposition reste « Nouveau » pendant une semaine après sa publication (sa validation par un administrateur). */
const NEW_FOR_MS = 7 * 24 * 60 * 60 * 1000;

function isNewProposal(createdAt: string): boolean {
  const age = Date.now() - new Date(createdAt).getTime();
  return age >= 0 && age < NEW_FOR_MS;
}

/**
 * Mois (AAAA-MM) sous lequel ranger une compétition : celui de son premier jour ; une compétition déjà commencée (sur
 * plusieurs jours) est rangée dans le mois en cours, pas dans un mois passé qui n'a plus d'intitulé à montrer.
 */
function monthKeyOf(race: RaceProposal, currentMonth: string): string {
  const key = race.raceDate.slice(0, 7);
  return key < currentMonth ? currentMonth : key;
}

/** « 2026-12 » ↦ « Décembre 2026 ». */
function monthTitle(key: string): string {
  const [year, month] = key.split('-').map(Number);
  const label = new Date(year, month - 1, 1).toLocaleDateString('fr-FR', { month: 'long', year: 'numeric' });
  return label.charAt(0).toUpperCase() + label.slice(1);
}

/**
 * Compétitions proposées par les adhérents (onglet Social) : onglet « À venir »
 * (toutes les propositions, rangées par mois, la plus proche d'abord, réponse directement depuis la
 * carte : « Je suis inscrit(e) » — « Partant(e) » quand un capitaine organise
 * l'inscription groupée — ou « Intéressé(e) ») + « Mes propositions » (modifier /
 * supprimer), filtrables par type (Tous, Triathlon, Trail, Cross…) ; label
 * « Nouveau » pendant une semaine. Dans « À venir », les compétitions où j'ai
 * répondu forment un bloc à part, au-dessus des autres. Dans chaque liste (et chaque
 * bloc), un intitulé « Décembre 2026 · 3 » précède les compétitions du mois.
 * Même organisation que la bourse aux équipements.
 *
 * Modération : « À venir » ne montre que les compétitions publiées (validées) ;
 * mes propres propositions « En attente de validation » ou « Non retenue » n'apparaissent que dans « Mes propositions ».
 */
export default function RacesScreen() {
  const router = useRouter();
  const { user } = useAuth();
  const [tab, setTab] = useState<Tab>('browse');
  const [items, setItems] = useState<RaceProposal[]>([]);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [busyId, setBusyId] = useState<number | null>(null);
  const [typeFilter, setTypeFilter] = useState<RaceType | 'all'>('all');

  const load = useCallback(async () => {
    try {
      setError(null);
      const resp = await racesApi.list();
      setItems(resp.data);
      // Liste affichée → plus de pastille « nouveautés » dans Social.
      if (user) void markSeen('races', user.id, resp.data.map(seenMark));
    } catch (e) {
      setError(e instanceof ApiError ? e.message : 'Erreur de chargement');
    } finally {
      setLoading(false);
      setRefreshing(false);
    }
  }, [user]);

  useFocusEffect(useCallback(() => { void load(); }, [load]));
  useRefreshOnResume(() => { void load(); });

  const mine = items.filter((r) => user !== null && r.authorId === user.id);
  // « À venir » : seulement ce qui est publié (mes propositions en attente / refusées restent dans « Mes propositions »).
  const published = useMemo(() => items.filter(isRacePublished), [items]);

  async function vote(item: RaceProposal, status: RaceVote) {
    setBusyId(item.id);
    try {
      // Re-tap sur son vote actuel = retrait.
      const updated = await racesApi.vote(item.id, item.myVote === status ? null : status);
      setItems((prev) => prev.map((r) => (r.id === updated.id ? updated : r)));
    } catch (e) {
      showError(e);
    } finally {
      setBusyId(null);
    }
  }

  async function remove(item: RaceProposal) {
    const confirmed = await confirmAsync(
      'Supprimer cette proposition ?',
      `« ${item.name} » et les votes associés seront définitivement supprimés.`,
    );
    if (!confirmed) return;
    setBusyId(item.id);
    try {
      await racesApi.remove(item.id);
      setItems((prev) => prev.filter((r) => r.id !== item.id));
    } catch (e) {
      showError(e);
    } finally {
      setBusyId(null);
    }
  }

  const tabItems = tab === 'browse' ? published : mine;

  // Filtres : les types présents dans l'onglet affiché (dans l'ordre de RACE_TYPES), avec leur nombre. Un type inconnu de
  // cette version de l'appli est rangé sous « Autre », comme sur sa carte.
  const typeChips = useMemo(
    () => RACE_TYPES
      .map((t) => ({ ...t, count: tabItems.filter((r) => raceTypeMeta(r.type).value === t.value).length }))
      .filter((t) => t.count > 0),
    [tabItems],
  );
  // Le type choisi peut disparaître (suppression, rechargement, autre onglet) : retour sur « Tous ».
  const activeFilter = typeFilter !== 'all' && typeChips.some((t) => t.value === typeFilter) ? typeFilter : 'all';
  const data = activeFilter === 'all' ? tabItems : tabItems.filter((r) => raceTypeMeta(r.type).value === activeFilter);

  // « À venir » en deux blocs : celles où j'ai répondu (inscrit(e) / partant(e) ou intéressé(e)) d'abord, puis les autres.
  // Répondre (ou retirer sa réponse) fait passer la carte d'un bloc à l'autre. Un seul bloc : pas d'intitulés de bloc.
  // Dans chaque bloc (et dans « Mes propositions »), les compétitions sont rangées par mois, du plus proche au plus lointain.
  const rows = useMemo<Row[]>(() => {
    const currentMonth = todayIso().slice(0, 7);
    const toRows = (list: RaceProposal[], scope: string): Row[] => {
      const sorted = [...list].sort((a, b) => a.raceDate.localeCompare(b.raceDate));
      const perMonth = new Map<string, number>();
      for (const r of sorted) {
        const key = monthKeyOf(r, currentMonth);
        perMonth.set(key, (perMonth.get(key) ?? 0) + 1);
      }
      const out: Row[] = [];
      let month = '';
      for (const item of sorted) {
        const key = monthKeyOf(item, currentMonth);
        if (key !== month) {
          month = key;
          out.push({ kind: 'month', key: `m-${scope}-${key}`, title: `${monthTitle(key)} · ${perMonth.get(key)}` });
        }
        out.push({ kind: 'race', key: tab + '-' + item.id, item });
      }
      return out;
    };
    if (tab !== 'browse') return toRows(data, 'mine');
    const answered = data.filter((r) => r.myVote);
    const others = data.filter((r) => !r.myVote);
    return [
      ...(answered.length > 0 && others.length > 0
        ? [{ kind: 'header', key: 'h-answered', title: `✅ Inscrit(e) ou intéressé(e) · ${answered.length}` } as Row]
        : []),
      ...toRows(answered, 'answered'),
      ...(answered.length > 0 && others.length > 0
        ? [{ kind: 'header', key: 'h-others', title: `🔎 À découvrir · ${others.length}` } as Row]
        : []),
      ...toRows(others, 'others'),
    ];
  }, [tab, data]);

  return (
    <View style={styles.container}>
      <Stack.Screen options={{ title: 'Compétitions proposées' }} />

      <View style={styles.tabs}>
        <Pressable onPress={() => setTab('browse')} style={[styles.tab, tab === 'browse' && styles.tabActive]}>
          <Text style={[styles.tabLabel, tab === 'browse' && styles.tabLabelActive]}>
            À venir{published.length > 0 ? ' · ' + published.length : ''}
          </Text>
        </Pressable>
        <Pressable onPress={() => setTab('mine')} style={[styles.tab, tab === 'mine' && styles.tabActive]}>
          <Text style={[styles.tabLabel, tab === 'mine' && styles.tabLabelActive]}>
            Mes propositions{mine.length > 0 ? ' · ' + mine.length : ''}
          </Text>
        </Pressable>
      </View>

      <Pressable style={styles.newButton} onPress={() => router.push('/races/new' as never)}>
        <Ionicons name="add-circle" size={20} color="#fff" />
        <Text style={styles.newButtonLabel}>Proposer une compétition</Text>
      </Pressable>

      {error ? (
        <ErrorState message={error} onRetry={load} />
      ) : (
        <FlatList
          data={rows}
          keyExtractor={(row) => row.key}
          contentContainerStyle={styles.list}
          refreshControl={<RefreshControl refreshing={refreshing} onRefresh={() => { setRefreshing(true); void load(); }} />}
          ListHeaderComponent={
            tabItems.length > 0 ? (
              <View style={styles.chips}>
                <FilterChip label="Tous" count={tabItems.length} active={activeFilter === 'all'} onPress={() => setTypeFilter('all')} />
                {typeChips.map((t) => (
                  <FilterChip
                    key={t.value}
                    label={`${t.emoji} ${t.short}`}
                    count={t.count}
                    active={activeFilter === t.value}
                    onPress={() => setTypeFilter(t.value)}
                  />
                ))}
              </View>
            ) : null
          }
          renderItem={({ item: row }) => {
            if (row.kind === 'header') return <Text style={styles.blockTitle}>{row.title}</Text>;
            if (row.kind === 'month') return <Text style={styles.monthTitle} accessibilityRole="header">{row.title}</Text>;
            const item = row.item;
            return (
              <RaceCard
                item={item}
                busy={busyId === item.id}
                onOpen={() => router.push(('/races/' + item.id) as never)}
                footer={tab === 'browse' ? (
                  <>
                    <ActionBtn
                      icon={item.myVote === 'interested' ? 'thumbs-up' : 'thumbs-up-outline'}
                      label={(item.captain ? 'Partant(e) · ' : 'Je suis inscrit(e) · ') + item.interestedCount}
                      active={item.myVote === 'interested'}
                      onPress={() => void vote(item, 'interested')}
                      disabled={busyId === item.id || item.votesClosed === true || !isRacePublished(item)}
                    />
                    <ActionBtn
                      icon={item.myVote === 'maybe' ? 'star' : 'star-outline'}
                      label={'Intéressé(e) · ' + item.maybeCount}
                      active={item.myVote === 'maybe'}
                      onPress={() => void vote(item, 'maybe')}
                      disabled={busyId === item.id || item.votesClosed === true || !isRacePublished(item)}
                    />
                  </>
                ) : (
                  <>
                    <ActionBtn
                      icon="create-outline"
                      label="Modifier"
                      onPress={() => router.push(('/races/' + item.id + '/edit') as never)}
                      disabled={busyId === item.id}
                    />
                    <ActionBtn
                      icon="trash-outline"
                      label="Supprimer"
                      onPress={() => void remove(item)}
                      disabled={busyId === item.id}
                      danger
                    />
                  </>
                )}
              />
            );
          }}
          ListEmptyComponent={
            !loading ? (
              <View style={styles.emptyCard}>
                <Ionicons name="flag-outline" size={32} color={COLORS.textMuted} />
                <Text style={styles.emptyLabel}>
                  {tab === 'browse'
                    ? 'Aucune compétition proposée pour le moment. Lancez la première !'
                    : "Vous n'avez pas encore proposé de compétition."}
                </Text>
              </View>
            ) : null
          }
        />
      )}
    </View>
  );
}

function RaceCard({ item, busy, onOpen, footer }: {
  item: RaceProposal;
  busy: boolean;
  onOpen: () => void;
  footer: React.ReactNode;
}) {
  const meta = raceTypeMeta(item.type);
  return (
    <View style={[styles.card, busy && { opacity: 0.7 }]}>
      <Pressable onPress={onOpen} style={({ pressed }) => [styles.cardRow, pressed && { opacity: 0.85 }]}>
        <View style={[styles.cardIcon, { backgroundColor: meta.color }]}>
          <Text style={styles.cardIconEmoji}>{meta.emoji}</Text>
        </View>
        <View style={{ flex: 1 }}>
          <Text style={styles.cardTitle} numberOfLines={2}>{item.name}</Text>
          <Text style={styles.cardDate}>
            {formatRaceDates(item.raceDate, item.raceEndDate)}
            {item.courses && item.courses.length > 0 ? ` · ${item.courses.length} course${item.courses.length > 1 ? 's' : ''}` : ''}
          </Text>
          <View style={styles.badgeRow}>
            <View style={[styles.typeBadge, { backgroundColor: meta.color }]}>
              <Text style={styles.typeBadgeLabel}>{item.typeLabel}</Text>
            </View>
            <RaceStatusBadge race={item} />
            {isRacePublished(item) && isNewProposal(item.publishedAt ?? item.createdAt) && (
              <View style={styles.newBadge} accessibilityLabel="Nouvelle proposition">
                <Text style={styles.newBadgeLabel}>Nouveau</Text>
              </View>
            )}
            {item.captain && (
              <View style={styles.captainBadge}>
                <Text style={styles.captainBadgeLabel}>🧢 Capitaine : {item.authorFirstName}</Text>
              </View>
            )}
          </View>
          {!item.captain && (
            <Text style={styles.cardMeta}>proposée par {item.authorFirstName}</Text>
          )}
          {/* Refus : le motif de l'administrateur, s'il y en a un. */}
          {raceStatus(item) === 'refused' && item.moderationNote ? (
            <Text style={styles.cardMeta}>Motif : {item.moderationNote}</Text>
          ) : null}
          {/* Capitaine : date limite de réponse avant l'inscription groupée. */}
          {item.captain && item.registrationDeadline ? (
            <Text style={[styles.cardMeta, item.votesClosed && styles.deadlineClosed]}>
              {item.votesClosed
                ? 'Réponses closes : date limite dépassée (' + formatRaceDate(item.registrationDeadline, false) + ')'
                : '⏳ Répondre avant le ' + formatRaceDate(item.registrationDeadline, false)}
            </Text>
          ) : null}
        </View>
        <Ionicons name="chevron-forward" size={20} color={COLORS.textMuted} />
      </Pressable>
      <View style={styles.cardActions}>{footer}</View>
    </View>
  );
}

function FilterChip({ label, count, active, onPress }: { label: string; count: number; active: boolean; onPress: () => void }) {
  return (
    <Pressable
      onPress={onPress}
      accessibilityRole="button"
      accessibilityState={{ selected: active }}
      style={({ pressed }) => [styles.chip, active && styles.chipActive, pressed && { opacity: 0.7 }]}
    >
      <Text style={[styles.chipLabel, active && styles.chipLabelActive]}>{label} · {count}</Text>
    </Pressable>
  );
}

function ActionBtn({ icon, label, onPress, disabled, danger, active }: {
  icon: keyof typeof Ionicons.glyphMap;
  label: string;
  onPress: () => void;
  disabled?: boolean;
  danger?: boolean;
  active?: boolean;
}) {
  const color = danger ? COLORS.error : active ? COLORS.primary : COLORS.textMuted;
  return (
    <Pressable
      onPress={onPress}
      disabled={disabled}
      style={({ pressed }) => [styles.actionBtn, active && styles.actionBtnActive, pressed && { opacity: 0.6 }]}
    >
      <Ionicons name={icon} size={16} color={color} />
      <Text style={[styles.actionBtnLabel, { color }]}>{label}</Text>
    </Pressable>
  );
}

function showError(e: unknown) {
  const msg = e instanceof ApiError ? e.message : 'Erreur inattendue.';
  if (Platform.OS === 'web') {
    if (typeof window !== 'undefined') window.alert(msg);
  } else {
    Alert.alert('Erreur', msg);
  }
}

function confirmAsync(title: string, message: string): Promise<boolean> {
  if (Platform.OS === 'web') {
    return Promise.resolve(typeof window !== 'undefined' ? window.confirm(title + '\n' + message) : false);
  }
  return new Promise((resolve) => {
    Alert.alert(title, message, [
      { text: 'Annuler', style: 'cancel', onPress: () => resolve(false) },
      { text: 'Supprimer', style: 'destructive', onPress: () => resolve(true) },
    ]);
  });
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: COLORS.background },
  tabs: {
    flexDirection: 'row', gap: SPACING.xs,
    paddingHorizontal: SPACING.md, paddingTop: SPACING.sm,
  },
  tab: {
    flex: 1, paddingVertical: 10, borderRadius: RADIUS.md,
    alignItems: 'center', backgroundColor: COLORS.surface,
    borderWidth: 1, borderColor: COLORS.border,
  },
  tabActive: { backgroundColor: COLORS.primary, borderColor: COLORS.primary },
  tabLabel: { fontSize: 13, fontWeight: '700', color: COLORS.textMuted },
  tabLabelActive: { color: '#fff' },
  newButton: {
    flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 8,
    backgroundColor: COLORS.secondary, marginHorizontal: SPACING.md, marginTop: SPACING.sm,
    paddingVertical: 12, borderRadius: RADIUS.md,
  },
  newButtonLabel: { color: '#fff', fontWeight: '700', fontSize: 14 },
  list: { padding: SPACING.md, gap: SPACING.sm, maxWidth: 640, width: '100%', alignSelf: 'center' },
  chips: { flexDirection: 'row', flexWrap: 'wrap', gap: 6, marginBottom: SPACING.sm },
  chip: {
    paddingHorizontal: 12, paddingVertical: 6, borderRadius: RADIUS.full,
    backgroundColor: COLORS.surface, borderWidth: 1, borderColor: COLORS.border,
  },
  chipActive: { backgroundColor: COLORS.primary, borderColor: COLORS.primary },
  chipLabel: { fontSize: 12, fontWeight: '700', color: COLORS.textMuted },
  chipLabelActive: { color: '#fff' },
  blockTitle: { fontSize: 13, fontWeight: '800', color: COLORS.secondaryDark, marginTop: SPACING.xs, marginBottom: SPACING.sm },
  monthTitle: {
    fontSize: 12, fontWeight: '800', color: COLORS.textMuted, textTransform: 'uppercase', letterSpacing: 0.6,
    marginTop: SPACING.xs, paddingBottom: 4, borderBottomWidth: 1, borderBottomColor: COLORS.border,
  },
  newBadge: { backgroundColor: '#dcfce7', paddingHorizontal: 8, paddingVertical: 2, borderRadius: 10 },
  newBadgeLabel: { fontSize: 11, fontWeight: '700', color: '#166534' },
  card: {
    backgroundColor: COLORS.surface, borderRadius: RADIUS.md,
    borderWidth: 1, borderColor: COLORS.border,
    marginBottom: SPACING.sm,
  },
  cardRow: { flexDirection: 'row', alignItems: 'center', gap: 12, padding: SPACING.sm },
  cardIcon: {
    width: 64, height: 64, borderRadius: RADIUS.sm,
    alignItems: 'center', justifyContent: 'center',
  },
  cardIconEmoji: { fontSize: 30 },
  cardTitle: { fontSize: 15, fontWeight: '700', color: COLORS.text },
  cardDate: { fontSize: 13, fontWeight: '600', color: COLORS.text, marginTop: 2 },
  cardMeta: { fontSize: 12, color: COLORS.textMuted, marginTop: 4 },
  badgeRow: { flexDirection: 'row', flexWrap: 'wrap', gap: 6, marginTop: 6 },
  typeBadge: { paddingHorizontal: 8, paddingVertical: 2, borderRadius: 10 },
  typeBadgeLabel: { fontSize: 11, fontWeight: '700', color: '#fff' },
  captainBadge: { backgroundColor: '#fef3c7', paddingHorizontal: 8, paddingVertical: 2, borderRadius: 10 },
  captainBadgeLabel: { fontSize: 11, fontWeight: '700', color: '#92400e' },
  deadlineClosed: { color: COLORS.error, fontWeight: '600' },
  cardActions: { flexDirection: 'row', borderTopWidth: 1, borderTopColor: COLORS.border },
  actionBtn: {
    flex: 1, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 6,
    paddingVertical: 10,
  },
  actionBtnActive: { backgroundColor: COLORS.primarySoft },
  actionBtnLabel: { fontSize: 12, fontWeight: '600' },
  emptyCard: {
    alignItems: 'center', gap: 8, padding: SPACING.xl,
    backgroundColor: COLORS.surface, borderRadius: RADIUS.md,
  },
  emptyLabel: { color: COLORS.textMuted, fontSize: 14, textAlign: 'center' },
});
