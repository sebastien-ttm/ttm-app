import Ionicons from '@expo/vector-icons/Ionicons';
import { Redirect, Stack, useLocalSearchParams } from 'expo-router';
import { memo, useCallback, useEffect, useMemo, useRef, useState } from 'react';
import {
  ActivityIndicator,
  FlatList,
  KeyboardAvoidingView,
  Platform,
  Pressable,
  RefreshControl,
  StyleSheet,
  Text,
  TextInput,
  View,
} from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { ApiError } from '@/api/client';
import { staffPerfTests } from '@/api/resources';
import type { PerfTestSheetRow, StaffPerfTestSheet } from '@/api/types';
import { useAuth } from '@/auth/AuthContext';
import { ErrorState, FullScreenLoading } from '@/components/Loading';
import { COLORS, RADIUS, SPACING } from '@/config';
import { canManageCapsAndTimes } from '@/utils/profile';

type TimeFilter = 'all' | 'todo' | 'done';

const FILTERS: { key: TimeFilter; label: string }[] = [
  { key: 'all', label: 'Tous' },
  { key: 'todo', label: 'Sans temps' },
  { key: 'done', label: 'Avec temps' },
];

/** Pour la recherche : sans accents ni casse. */
function normalize(text: string): string {
  return text.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
}

/**
 * Saisie des temps d'une prise de temps (espace Staff, entraîneurs) : un champ
 * par adhérent, enregistré dès qu'on quitte le champ ou valide au clavier.
 * Format libre : « 5:42 », « 5.42 », « 1:02:15 » ou un nombre de secondes ;
 * un champ vidé efface le temps. Le dernier temps et le record de l'adhérent
 * sur la même épreuve sont rappelés pour repérer une faute de frappe.
 */
export default function StaffPerfTestSheetScreen() {
  const { user } = useAuth();
  const { id: rawId } = useLocalSearchParams<{ id: string }>();
  const sessionId = Number(rawId);

  const [sheet, setSheet] = useState<StaffPerfTestSheet | null>(null);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [query, setQuery] = useState('');
  const [filter, setFilter] = useState<TimeFilter>('all');

  const load = useCallback(async () => {
    try {
      setError(null);
      setSheet(await staffPerfTests.sheet(sessionId));
    } catch (e) {
      setError(e instanceof ApiError ? e.message : 'Erreur de chargement');
    } finally {
      setLoading(false);
    }
  }, [sessionId]);

  useEffect(() => { void load(); }, [load]);

  const onRefresh = useCallback(async () => {
    setRefreshing(true);
    await load();
    setRefreshing(false);
  }, [load]);

  /** Enregistre un temps ; renvoie un message d'erreur, ou null si c'est enregistré. */
  const save = useCallback(async (userId: number, value: string): Promise<string | null> => {
    try {
      const state = await staffPerfTests.saveTime(sessionId, userId, value);
      setSheet((current) => {
        if (!current) return current;
        const data = current.data.map((r) => (r.id === userId ? { ...r, ...state } : r));
        return { ...current, data, enteredCount: data.filter((r) => r.time !== null).length };
      });
      return null;
    } catch (e) {
      return e instanceof ApiError ? e.message : 'Enregistrement impossible. Réessayez.';
    }
  }, [sessionId]);

  const rows = useMemo(() => {
    if (!sheet) return [];
    const q = normalize(query.trim());
    return sheet.data.filter((r) => {
      if (filter === 'todo' && r.time !== null) return false;
      if (filter === 'done' && r.time === null) return false;
      return q === '' || normalize(`${r.nom} ${r.prenom}`).includes(q) || normalize(`${r.prenom} ${r.nom}`).includes(q);
    });
  }, [sheet, query, filter]);

  if (!canManageCapsAndTimes(user)) {
    return <Redirect href="/(tabs)" />;
  }

  if (loading) {
    return (
      <SafeAreaView style={styles.root}>
        <Stack.Screen options={{ title: 'Saisie des temps' }} />
        <FullScreenLoading />
      </SafeAreaView>
    );
  }
  if (error || !sheet) {
    return (
      <SafeAreaView style={styles.root}>
        <Stack.Screen options={{ title: 'Saisie des temps' }} />
        <ErrorState message={error ?? 'Prise de temps introuvable'} onRetry={load} />
      </SafeAreaView>
    );
  }

  const { session } = sheet;

  return (
    <SafeAreaView style={styles.root} edges={['bottom']}>
      <Stack.Screen options={{ title: 'Saisie des temps' }} />
      <KeyboardAvoidingView style={{ flex: 1 }} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
        <View style={styles.header}>
          <Text style={styles.sessionTitle} numberOfLines={2}>{session.icon} {session.label}</Text>
          <Text style={styles.sessionSub}>
            {session.datesLabel}{session.notes ? ` · ${session.notes}` : ''}
          </Text>
          <Text style={styles.counter}>
            <Text style={styles.counterNumber}>{sheet.enteredCount}</Text> temps saisi{sheet.enteredCount > 1 ? 's' : ''}
            {sheet.legacyCount > 0 ? ` · +${sheet.legacyCount} ancien${sheet.legacyCount > 1 ? 's' : ''} adhérent${sheet.legacyCount > 1 ? 's' : ''} (backend)` : ''}
          </Text>
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
            return (
              <Pressable
                key={f.key}
                onPress={() => setFilter(f.key)}
                accessibilityRole="button"
                accessibilityState={{ selected: active }}
                style={[styles.pill, active && styles.pillActive]}
              >
                <Text style={[styles.pillLabel, active && styles.pillLabelActive]}>{f.label}</Text>
              </Pressable>
            );
          })}
        </View>

        <FlatList
          data={rows}
          keyExtractor={(r) => String(r.id)}
          keyboardShouldPersistTaps="handled"
          contentContainerStyle={styles.listContent}
          refreshControl={<RefreshControl refreshing={refreshing} onRefresh={onRefresh} tintColor={COLORS.primary} />}
          ListEmptyComponent={
            <Text style={styles.empty}>
              {query !== '' ? 'Aucun adhérent trouvé.' : filter === 'todo' ? 'Tous les temps sont saisis. 🎉' : 'Aucun temps saisi pour l\'instant.'}
            </Text>
          }
          renderItem={({ item }) => <TimeRow row={item} onSave={save} />}
        />
      </KeyboardAvoidingView>
    </SafeAreaView>
  );
}

/**
 * Ligne d'un adhérent. Le brouillon reste local à la ligne : taper ne refait
 * pas le rendu de toute la liste. En cas d'erreur, le brouillon est conservé
 * pour être corrigé.
 */
const TimeRow = memo(function TimeRow({
  row,
  onSave,
}: {
  row: PerfTestSheetRow;
  onSave: (userId: number, value: string) => Promise<string | null>;
}) {
  const [draft, setDraft] = useState(row.time ?? '');
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const inFlight = useRef(false);

  // Le serveur renvoie le temps normalisé (« 5.42 » → « 5:42 ») : on l'affiche.
  useEffect(() => { setDraft(row.time ?? ''); }, [row.time]);

  async function commit() {
    const value = draft.trim();
    if (inFlight.current) return;
    if (value === (row.time ?? '')) {
      setDraft(row.time ?? '');
      setError(null);
      return;
    }
    inFlight.current = true;
    setSaving(true);
    setError(null);
    const failure = await onSave(row.id, value);
    inFlight.current = false;
    setSaving(false);
    if (failure) setError(failure);
  }

  const hint = [
    row.categorie,
    row.last ? `Préc. ${row.last.time} (${row.last.date})` : null,
    row.best && row.best !== row.last?.time ? `Record ${row.best}` : null,
  ].filter(Boolean).join(' · ');

  return (
    <View style={[styles.row, row.time !== null && !error && styles.rowDone]}>
      <View style={styles.rowMain}>
        <View style={{ flex: 1 }}>
          <Text style={styles.name} numberOfLines={1}>
            <Text style={styles.nom}>{row.nom.toUpperCase()}</Text> {row.prenom}
          </Text>
          {hint !== '' && <Text style={styles.hint} numberOfLines={1}>{hint}</Text>}
        </View>
        <View style={styles.statusSlot}>
          {saving ? (
            <ActivityIndicator size="small" color={COLORS.primary} />
          ) : error ? (
            <Ionicons name="alert-circle" size={20} color={COLORS.error} />
          ) : row.warning ? (
            <Ionicons name="warning" size={20} color="#d97706" />
          ) : row.time !== null ? (
            <Ionicons name="checkmark-circle" size={20} color={COLORS.success} />
          ) : null}
        </View>
        <TextInput
          value={draft}
          onChangeText={(t) => { setDraft(t); if (error) setError(null); }}
          onBlur={commit}
          onSubmitEditing={commit}
          placeholder="m:ss"
          placeholderTextColor={COLORS.textSubtle}
          keyboardType="numbers-and-punctuation"
          autoCapitalize="none"
          autoCorrect={false}
          returnKeyType="done"
          selectTextOnFocus
          accessibilityLabel={`Temps de ${row.prenom} ${row.nom}`}
          style={[styles.input, error ? styles.inputError : null]}
        />
      </View>
      {error ? <Text style={styles.errorText}>{error}</Text> : null}
      {!error && row.warning ? <Text style={styles.warningText}>{row.warning}</Text> : null}
    </View>
  );
});

const styles = StyleSheet.create({
  root: { flex: 1, backgroundColor: COLORS.background },
  header: { padding: SPACING.md, paddingBottom: SPACING.sm, gap: 2 },
  sessionTitle: { fontSize: 18, fontWeight: '800', color: COLORS.text },
  sessionSub: { fontSize: 13, color: COLORS.textMuted },
  counter: { fontSize: 13, color: COLORS.textMuted, marginTop: 4 },
  counterNumber: { fontSize: 20, fontWeight: '800', color: COLORS.secondaryDark },
  searchWrap: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 8,
    marginHorizontal: SPACING.md,
    marginBottom: 8,
    paddingHorizontal: 12,
    borderRadius: RADIUS.md,
    borderWidth: 1,
    borderColor: COLORS.border,
    backgroundColor: COLORS.surface,
  },
  search: { flex: 1, paddingVertical: 11, fontSize: 15, color: COLORS.text },
  filters: { flexDirection: 'row', gap: SPACING.sm, paddingHorizontal: SPACING.md, marginBottom: 6 },
  pill: {
    paddingHorizontal: 12,
    paddingVertical: 6,
    borderRadius: RADIUS.full,
    borderWidth: 1,
    borderColor: COLORS.border,
    backgroundColor: COLORS.surface,
  },
  pillActive: { backgroundColor: COLORS.brandNavy, borderColor: COLORS.brandNavy },
  pillLabel: { fontSize: 12, fontWeight: '700', color: COLORS.text },
  pillLabelActive: { color: '#fff' },
  listContent: { paddingBottom: SPACING.xxl },
  empty: { textAlign: 'center', color: COLORS.textMuted, padding: SPACING.xl, fontSize: 14 },
  row: {
    backgroundColor: COLORS.surface,
    paddingHorizontal: SPACING.md,
    paddingVertical: 10,
    borderBottomWidth: StyleSheet.hairlineWidth,
    borderBottomColor: COLORS.border,
  },
  rowDone: { backgroundColor: '#f0fdf4' },
  rowMain: { flexDirection: 'row', alignItems: 'center', gap: 10 },
  name: { fontSize: 16, color: COLORS.text },
  nom: { fontWeight: '700' },
  hint: { fontSize: 11, color: COLORS.textMuted, marginTop: 2 },
  statusSlot: { width: 22, alignItems: 'center' },
  input: {
    width: 96,
    paddingVertical: 9,
    paddingHorizontal: 10,
    borderRadius: RADIUS.md,
    borderWidth: 1,
    borderColor: COLORS.border,
    backgroundColor: '#fff',
    fontSize: 17,
    fontWeight: '700',
    color: COLORS.text,
    textAlign: 'center',
  },
  inputError: { borderColor: COLORS.error },
  errorText: { fontSize: 12, color: COLORS.error, marginTop: 4 },
  warningText: { fontSize: 12, color: '#b45309', marginTop: 4 },
});
