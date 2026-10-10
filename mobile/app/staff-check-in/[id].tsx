import Ionicons from '@expo/vector-icons/Ionicons';
import { Redirect, Stack, useLocalSearchParams } from 'expo-router';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import {
  Alert,
  Platform,
  Pressable,
  RefreshControl,
  SectionList,
  StyleSheet,
  Text,
  TextInput,
  View,
} from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { ApiError } from '@/api/client';
import { staffCheckIn } from '@/api/resources';
import type { CheckInLive, CheckInRow, CheckInSheet, CheckInVote } from '@/api/types';
import { useAuth } from '@/auth/AuthContext';
import { formatEventPeriod } from '@/components/gestion/EmargementTab';
import { ErrorState, FullScreenLoading } from '@/components/Loading';
import { LiveStatus } from '@/components/LiveStatus';
import { MemberAvatar } from '@/components/MemberAvatar';
import { COLORS, RADIUS, SPACING } from '@/config';
import { useFlashRows, useLiveSync } from '@/hooks/useLiveSync';
import { canCheckIn } from '@/utils/profile';

const VOTE_TITLES: Record<CheckInVote, string> = {
  yes: '✅ Ont voté « présent »',
  maybe: '❓ Peut-être',
  no: '❌ Ont voté « absent »',
  none: 'Sans vote',
};
const VOTE_ORDER: CheckInVote[] = ['yes', 'maybe', 'no', 'none'];

/** Pour la recherche : sans accents ni casse. */
function normalize(text: string): string {
  return text.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
}

type CheckInFilter = 'registered' | 'all';

const FILTERS: { key: CheckInFilter; label: string }[] = [
  { key: 'registered', label: 'Inscrits' },
  { key: 'all', label: 'Tous' },
];

/**
 * Feuille d'émargement d'un événement soumis au vote (espace Staff) : un appui
 * sur un adhérent coche / décoche sa présence, immédiatement enregistrée. Deux
 * filtres : « Inscrits » (par défaut) ne montre que ceux qui ont voté « présent »
 * (et ceux déjà émargés, pour ne jamais les perdre de vue) ; « Tous » montre tous
 * les adhérents, y compris ceux qui n'ont pas voté, pour émarger quelqu'un venu
 * sans s'être inscrit.
 */
export default function StaffCheckInScreen() {
  const { user } = useAuth();
  const { id: rawId } = useLocalSearchParams<{ id: string }>();
  const eventId = Number(rawId);

  const [sheet, setSheet] = useState<CheckInSheet | null>(null);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [query, setQuery] = useState('');
  const [pending, setPending] = useState<Set<number>>(new Set());
  const [filter, setFilter] = useState<CheckInFilter>('registered');
  // Adhérents émargés / décochés pendant cette session : ils restent dans « Inscrits »
  // même si on les décoche, pour que la ligne ne disparaisse pas sous le doigt.
  const [touched, setTouched] = useState<Set<number>>(new Set());
  // Appuis en cours d'enregistrement (copie en ref lisible par la synchronisation) : leurs lignes ne
  // sont pas écrasées par l'état du serveur le temps que la réponse arrive.
  const pendingRef = useRef<Set<number>>(new Set());
  const sheetRef = useRef<CheckInSheet | null>(null);
  sheetRef.current = sheet;
  // Lignes modifiées par quelqu'un d'autre : surlignées un instant.
  const { flashed, flash } = useFlashRows();

  const load = useCallback(async () => {
    try {
      setError(null);
      setSheet(await staffCheckIn.sheet(eventId));
    } catch (e) {
      setError(e instanceof ApiError ? e.message : 'Erreur de chargement');
    } finally {
      setLoading(false);
    }
  }, [eventId]);

  useEffect(() => { void load(); }, [load]);

  const onRefresh = useCallback(async () => {
    setRefreshing(true);
    await load();
    setRefreshing(false);
  }, [load]);

  // Synchronisation en direct : émargements et votes des autres, sans rafraîchir la page.
  const onLiveState = useCallback((state: CheckInLive) => {
    const checkedById = new Map(state.checked.map((c) => [c.id, c]));
    const voteById = new Map<number, CheckInVote>(state.votes);
    const current = sheetRef.current;
    const changed = current
      ? current.data
        .filter((r) => !pendingRef.current.has(r.id) && r.checked !== checkedById.has(r.id))
        .map((r) => r.id)
      : [];

    setSheet((sheetNow) => {
      if (!sheetNow) return sheetNow;
      let modified = false;
      const data = sheetNow.data.map((r) => {
        if (pendingRef.current.has(r.id)) return r;
        const c = checkedById.get(r.id);
        const vote = voteById.get(r.id) ?? 'none';
        const checked = c !== undefined;
        const same = r.checked === checked && r.vote === vote
          && (!checked || (r.checkedAt === c.checkedAt && r.checkedBy === c.checkedBy));
        if (same) return r;
        modified = true;
        return { ...r, vote, checked, checkedAt: c?.checkedAt ?? null, checkedBy: c?.checkedBy ?? null };
      });
      return modified ? { ...sheetNow, data, checkedCount: data.filter((r) => r.checked).length } : sheetNow;
    });
    flash(changed);
  }, [flash]);

  const { online, resync } = useLiveSync<CheckInLive>({
    enabled: sheet !== null,
    fetchState: (version) => staffCheckIn.state(eventId, version),
    onState: onLiveState,
  });

  /** Inscrit = a voté « présent » ; un adhérent déjà émargé (ou touché ici) reste visible. */
  const isRegistered = useCallback(
    (r: CheckInRow) => r.vote === 'yes' || r.checked || touched.has(r.id),
    [touched],
  );

  const registeredCount = useMemo(() => (sheet ? sheet.data.filter(isRegistered).length : 0), [sheet, isRegistered]);

  const sections = useMemo(() => {
    if (!sheet) return [];
    const q = normalize(query.trim());
    const rows = sheet.data.filter((r) => {
      if (filter === 'registered' && !isRegistered(r)) return false;
      return q === '' || normalize(`${r.nom} ${r.prenom}`).includes(q) || normalize(`${r.prenom} ${r.nom}`).includes(q);
    });
    return VOTE_ORDER
      .map((vote) => ({ vote, title: VOTE_TITLES[vote], data: rows.filter((r) => r.vote === vote) }))
      .filter((s) => s.data.length > 0);
  }, [sheet, query, filter, isRegistered]);

  if (!canCheckIn(user)) {
    return <Redirect href="/(tabs)" />;
  }

  async function toggle(row: CheckInRow) {
    if (pending.has(row.id) || !sheet) return;
    const next = !row.checked;
    pendingRef.current.add(row.id);
    setPending((p) => new Set(p).add(row.id));
    setTouched((t) => new Set(t).add(row.id));
    // Optimiste : l'appui se voit tout de suite ; retour arrière si le serveur refuse.
    patch(row.id, { checked: next, checkedAt: null, checkedBy: null });
    try {
      const state = await staffCheckIn.setChecked(eventId, row.id, next);
      patch(row.id, state);
    } catch (e) {
      patch(row.id, { checked: row.checked, checkedAt: row.checkedAt, checkedBy: row.checkedBy });
      const msg = e instanceof ApiError ? e.message : 'Enregistrement impossible. Réessayez.';
      if (Platform.OS === 'web') window.alert(msg);
      else Alert.alert('Émargement non enregistré', msg);
    } finally {
      pendingRef.current.delete(row.id);
      resync(); // recale la feuille sur le serveur au prochain passage (retour arrière, ou autre appui entre-temps)
      setPending((p) => {
        const copy = new Set(p);
        copy.delete(row.id);
        return copy;
      });
    }
  }

  /** Met à jour une ligne et recalcule le compteur d'émargés. */
  function patch(userId: number, state: Pick<CheckInRow, 'checked' | 'checkedAt' | 'checkedBy'>) {
    setSheet((current) => {
      if (!current) return current;
      const data = current.data.map((r) => (r.id === userId ? { ...r, ...state } : r));
      return { ...current, data, checkedCount: data.filter((r) => r.checked).length };
    });
  }

  if (loading) {
    return (
      <SafeAreaView style={styles.root}>
        <Stack.Screen options={{ title: 'Émargement' }} />
        <FullScreenLoading />
      </SafeAreaView>
    );
  }
  if (error || !sheet) {
    return (
      <SafeAreaView style={styles.root}>
        <Stack.Screen options={{ title: 'Émargement' }} />
        <ErrorState message={error ?? 'Événement introuvable'} onRetry={load} />
      </SafeAreaView>
    );
  }

  return (
    <SafeAreaView style={styles.root} edges={['bottom']}>
      <Stack.Screen options={{ title: 'Émargement' }} />

      <View style={styles.header}>
        <Text style={styles.eventTitle} numberOfLines={2}>{sheet.event.title}</Text>
        <Text style={styles.eventSub}>
          {formatEventPeriod(sheet.event)}
          {sheet.event.location ? ` · ${sheet.event.location}` : ''}
        </Text>
        <View style={styles.counterRow}>
          <Text style={styles.counter}>
            <Text style={styles.counterNumber}>{sheet.checkedCount}</Text> émargé{sheet.checkedCount > 1 ? 's' : ''}
          </Text>
          <LiveStatus online={online} />
        </View>
      </View>

      <View style={styles.searchWrap}>
        <Ionicons name="search" size={18} color={COLORS.textMuted} />
        <TextInput
          value={query}
          onChangeText={setQuery}
          placeholder="Chercher un adhérent"
          placeholderTextColor={COLORS.textSubtle}
          autoCapitalize="none"
          autoCorrect={false}
          style={styles.search}
        />
        {query !== '' && (
          <Pressable onPress={() => setQuery('')} hitSlop={10} accessibilityLabel="Effacer la recherche">
            <Ionicons name="close-circle" size={18} color={COLORS.textSubtle} />
          </Pressable>
        )}
      </View>

      <View style={styles.filters}>
        {FILTERS.map((f) => {
          const active = f.key === filter;
          const count = f.key === 'registered' ? registeredCount : sheet.data.length;
          return (
            <Pressable
              key={f.key}
              onPress={() => setFilter(f.key)}
              accessibilityRole="button"
              accessibilityState={{ selected: active }}
              style={[styles.pill, active && styles.pillActive]}
            >
              <Text style={[styles.pillLabel, active && styles.pillLabelActive]}>{f.label} ({count})</Text>
            </Pressable>
          );
        })}
      </View>

      <SectionList
        sections={sections}
        extraData={flashed}
        keyExtractor={(r) => String(r.id)}
        stickySectionHeadersEnabled
        keyboardShouldPersistTaps="handled"
        contentContainerStyle={styles.listContent}
        refreshControl={<RefreshControl refreshing={refreshing} onRefresh={onRefresh} tintColor={COLORS.primary} />}
        ListEmptyComponent={
          <View style={styles.emptyWrap}>
            <Text style={styles.empty}>
              {filter === 'registered'
                ? (query === '' ? 'Personne ne s\'est encore inscrit.' : 'Aucun inscrit trouvé.')
                : 'Aucun adhérent trouvé.'}
            </Text>
            {filter === 'registered' && (
              <Pressable onPress={() => setFilter('all')} accessibilityRole="button" style={styles.emptyLink}>
                <Text style={styles.emptyLinkLabel}>Voir tous les adhérents</Text>
              </Pressable>
            )}
          </View>
        }
        renderSectionHeader={({ section }) => (
          <View style={styles.sectionHeader}>
            <Text style={styles.sectionTitle}>{section.title} ({section.data.length})</Text>
          </View>
        )}
        renderItem={({ item }) => (
          <Pressable
            onPress={() => toggle(item)}
            accessibilityRole="checkbox"
            accessibilityState={{ checked: item.checked }}
            accessibilityLabel={`${item.prenom} ${item.nom}`}
            style={({ pressed }) => [styles.row, item.checked && styles.rowChecked, flashed.has(item.id) && styles.rowFlash, pressed && { opacity: 0.75 }]}
          >
            {/* Photo non cliquable ici : un appui n'importe où sur la ligne coche / décoche. */}
            <MemberAvatar prenom={item.prenom} nom={item.nom} avatarUrl={item.avatarUrl} size={48} />
            <View style={{ flex: 1 }}>
              <Text style={styles.name} numberOfLines={1}>
                <Text style={styles.nom}>{item.nom.toUpperCase()}</Text> {item.prenom}
              </Text>
              {item.checked && (item.checkedAt || item.checkedBy) && (
                <Text style={styles.checkedInfo}>
                  Émargé{item.checkedAt ? ` à ${item.checkedAt}` : ''}{item.checkedBy ? ` par ${item.checkedBy}` : ''}
                </Text>
              )}
            </View>
            <Ionicons
              name={item.checked ? 'checkbox' : 'square-outline'}
              size={30}
              color={item.checked ? COLORS.success : COLORS.textSubtle}
            />
          </Pressable>
        )}
      />
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  root: { flex: 1, backgroundColor: COLORS.background },
  header: { padding: SPACING.md, paddingBottom: SPACING.sm, gap: 2 },
  eventTitle: { fontSize: 18, fontWeight: '800', color: COLORS.text },
  eventSub: { fontSize: 13, color: COLORS.textMuted },
  counterRow: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', marginTop: 4 },
  counter: { fontSize: 14, color: COLORS.textMuted },
  counterNumber: { fontSize: 20, fontWeight: '800', color: COLORS.success },
  searchWrap: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 8,
    marginHorizontal: SPACING.md,
    marginBottom: 6,
    paddingHorizontal: 12,
    borderRadius: RADIUS.md,
    borderWidth: 1,
    borderColor: COLORS.border,
    backgroundColor: COLORS.surface,
  },
  search: { flex: 1, paddingVertical: 11, fontSize: 15, color: COLORS.text },
  listContent: { paddingBottom: SPACING.xxl },
  filters: { flexDirection: 'row', gap: SPACING.sm, paddingHorizontal: SPACING.md, marginBottom: 6 },
  pill: {
    paddingHorizontal: 14,
    paddingVertical: 7,
    borderRadius: RADIUS.full,
    borderWidth: 1,
    borderColor: COLORS.border,
    backgroundColor: COLORS.surface,
  },
  pillActive: { backgroundColor: COLORS.brandNavy, borderColor: COLORS.brandNavy },
  pillLabel: { fontSize: 13, fontWeight: '700', color: COLORS.text },
  pillLabelActive: { color: '#fff' },
  emptyWrap: { alignItems: 'center' },
  empty: { textAlign: 'center', color: COLORS.textMuted, padding: SPACING.xl, paddingBottom: SPACING.md, fontSize: 14 },
  emptyLink: { paddingHorizontal: 16, paddingVertical: 10, borderRadius: RADIUS.md, borderWidth: 1, borderColor: COLORS.border, backgroundColor: COLORS.surface },
  emptyLinkLabel: { fontSize: 13, fontWeight: '700', color: COLORS.secondaryDark },
  sectionHeader: { backgroundColor: COLORS.background, paddingHorizontal: SPACING.md, paddingVertical: 5 },
  sectionTitle: { fontSize: 12, fontWeight: '800', color: COLORS.secondaryDark },
  row: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 12,
    backgroundColor: COLORS.surface,
    paddingHorizontal: SPACING.md,
    paddingVertical: 12,
    minHeight: 56,
    borderBottomWidth: StyleSheet.hairlineWidth,
    borderBottomColor: COLORS.border,
  },
  rowChecked: { backgroundColor: '#f0fdf4' },
  // Modifiée à l'instant par quelqu'un d'autre.
  rowFlash: { backgroundColor: '#fef9c3' },
  name: { fontSize: 16, color: COLORS.text },
  nom: { fontWeight: '700' },
  checkedInfo: { fontSize: 12, color: '#166534', marginTop: 2 },
});
