import Ionicons from '@expo/vector-icons/Ionicons';
import { Redirect, Stack } from 'expo-router';
import { useCallback, useEffect, useMemo, useState } from 'react';
import {
  ActivityIndicator,
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
import { staffCaps } from '@/api/resources';
import type { CapRow, CapState } from '@/api/types';
import { useAuth } from '@/auth/AuthContext';
import { EmptyState, ErrorState, FullScreenLoading } from '@/components/Loading';
import { MemberAvatar } from '@/components/MemberAvatar';
import { COLORS, RADIUS, SPACING } from '@/config';
import { confirmAction } from '@/utils/confirm';
import { canManageCapsAndTimes } from '@/utils/profile';

type CapFilter = 'all' | 'todo' | 'done';

/** Pour la recherche : sans accents ni casse. */
function normalize(text: string): string {
  return text.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
}

/**
 * Émargement de la remise des bonnets de bain du club (espace Staff) : un
 * bouton « Remis » par adhérent, enregistré tout de suite ; remplacement
 * possible (bonnet perdu ou abîmé) et annulation d'un appui par erreur.
 */
export default function StaffCapsScreen() {
  const { user } = useAuth();
  const [rows, setRows] = useState<CapRow[]>([]);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [query, setQuery] = useState('');
  const [filter, setFilter] = useState<CapFilter>('all');
  const [busyId, setBusyId] = useState<number | null>(null);

  const load = useCallback(async () => {
    try {
      setError(null);
      setRows((await staffCaps.list()).data);
    } catch (e) {
      setError(e instanceof ApiError ? e.message : 'Erreur de chargement');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => { void load(); }, [load]);

  const onRefresh = useCallback(async () => {
    setRefreshing(true);
    await load();
    setRefreshing(false);
  }, [load]);

  const received = rows.filter((r) => r.count > 0).length;

  const sections = useMemo(() => {
    const q = normalize(query.trim());
    const filtered = rows.filter((r) => {
      if (filter === 'todo' && r.count > 0) return false;
      if (filter === 'done' && r.count === 0) return false;
      return q === '' || normalize(`${r.nom} ${r.prenom}`).includes(q) || normalize(`${r.prenom} ${r.nom}`).includes(q);
    });
    const byLetter = new Map<string, CapRow[]>();
    for (const r of filtered) {
      const letter = normalize(r.nom.trim()).charAt(0).toUpperCase();
      const key = /[A-Z]/.test(letter) ? letter : '#';
      byLetter.set(key, [...(byLetter.get(key) ?? []), r]);
    }
    return Array.from(byLetter.entries())
      .sort(([a], [b]) => (a === '#' ? 1 : b === '#' ? -1 : a.localeCompare(b)))
      .map(([title, data]) => ({ title, data }));
  }, [rows, query, filter]);

  if (!canManageCapsAndTimes(user)) {
    return <Redirect href="/(tabs)" />;
  }

  function apply(userId: number, state: CapState) {
    setRows((current) => current.map((r) => (r.id === userId ? { ...r, ...state } : r)));
  }

  function fail(e: unknown) {
    const msg = e instanceof ApiError ? e.message : 'Enregistrement impossible. Réessayez.';
    if (Platform.OS === 'web') window.alert(msg);
    else Alert.alert('Non enregistré', msg);
  }

  async function run(row: CapRow, action: () => Promise<CapState>) {
    if (busyId !== null) return;
    setBusyId(row.id);
    try {
      apply(row.id, await action());
    } catch (e) {
      fail(e);
    } finally {
      setBusyId(null);
    }
  }

  const give = (row: CapRow) => run(row, () => staffCaps.give(row.id));

  async function replace(row: CapRow) {
    const ok = await confirmAction(
      'Remplacer le bonnet ?',
      `${row.prenom} ${row.nom} a déjà reçu un bonnet. Enregistrer une nouvelle remise (bonnet perdu ou abîmé) ?`,
      'Nouvelle remise',
    );
    if (ok) await run(row, () => staffCaps.give(row.id));
  }

  async function undo(row: CapRow) {
    const ok = await confirmAction(
      'Annuler la dernière remise ?',
      `Retirer la dernière remise de bonnet de ${row.prenom} ${row.nom} (appui par erreur) ?`,
      'Annuler la remise',
    );
    if (ok) await run(row, () => staffCaps.undo(row.id));
  }

  if (loading) {
    return (
      <SafeAreaView style={styles.root}>
        <Stack.Screen options={{ title: 'Remise des bonnets' }} />
        <FullScreenLoading />
      </SafeAreaView>
    );
  }
  if (error && rows.length === 0) {
    return (
      <SafeAreaView style={styles.root}>
        <Stack.Screen options={{ title: 'Remise des bonnets' }} />
        <ErrorState message={error} onRetry={load} />
      </SafeAreaView>
    );
  }

  const FILTERS: { key: CapFilter; label: string }[] = [
    { key: 'all', label: `Tous (${rows.length})` },
    { key: 'todo', label: `À remettre (${rows.length - received})` },
    { key: 'done', label: `Remis (${received})` },
  ];

  return (
    <SafeAreaView style={styles.root} edges={['bottom']}>
      <Stack.Screen options={{ title: 'Remise des bonnets' }} />

      <View style={styles.header}>
        <Text style={styles.counter}>
          <Text style={styles.counterNumber}>{received}</Text> / {rows.length} adhérents ont reçu leur bonnet
        </Text>
      </View>

      <View style={styles.searchWrap}>
        <Ionicons name="search" size={18} color={COLORS.textMuted} />
        <TextInput
          value={query}
          onChangeText={setQuery}
          placeholder="Rechercher un nom ou un prénom"
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
          const active = filter === f.key;
          return (
            <Pressable
              key={f.key}
              onPress={() => setFilter(f.key)}
              accessibilityRole="button"
              accessibilityState={{ selected: active }}
              style={[styles.chip, active && styles.chipActive]}
            >
              <Text style={[styles.chipLabel, active && styles.chipLabelActive]} numberOfLines={1}>{f.label}</Text>
            </Pressable>
          );
        })}
      </View>

      <SectionList
        sections={sections}
        keyExtractor={(r) => String(r.id)}
        stickySectionHeadersEnabled
        keyboardShouldPersistTaps="handled"
        contentContainerStyle={styles.listContent}
        refreshControl={<RefreshControl refreshing={refreshing} onRefresh={onRefresh} tintColor={COLORS.primary} />}
        ListEmptyComponent={<EmptyState icon="🔎" title="Aucun adhérent" message="Modifiez la recherche ou le filtre." />}
        renderSectionHeader={({ section }) => (
          <View style={styles.sectionHeader}>
            <Text style={styles.sectionLetter}>{section.title}</Text>
          </View>
        )}
        renderItem={({ item }) => {
          const busy = busyId === item.id;
          return (
            <View style={styles.row}>
              <MemberAvatar prenom={item.prenom} nom={item.nom} avatarUrl={item.avatarUrl} size={44} />
              <View style={{ flex: 1 }}>
                <Text style={styles.name} numberOfLines={2}>
                  <Text style={styles.nom}>{item.nom.toUpperCase()}</Text> {item.prenom}
                  {item.categorie ? <Text style={styles.cat}>  {item.categorie}</Text> : null}
                </Text>
                {item.count > 0 && (
                  <Text style={styles.done}>
                    ✓ Remis le {item.lastAt}{item.lastBy ? ` par ${item.lastBy}` : ''}
                    {item.count > 1 ? ` · ${item.count} remises` : ''}
                  </Text>
                )}
              </View>

              {busy ? (
                <ActivityIndicator color={COLORS.secondary} />
              ) : item.count === 0 ? (
                <Pressable
                  onPress={() => give(item)}
                  accessibilityRole="button"
                  accessibilityLabel={`Bonnet remis à ${item.prenom} ${item.nom}`}
                  style={({ pressed }) => [styles.giveBtn, pressed && { opacity: 0.8 }]}
                >
                  <Ionicons name="checkmark" size={18} color="#fff" />
                  <Text style={styles.giveLabel}>Remis</Text>
                </Pressable>
              ) : (
                <View style={styles.actions}>
                  <Pressable onPress={() => replace(item)} hitSlop={6} style={({ pressed }) => [styles.smallBtn, pressed && { opacity: 0.7 }]}>
                    <Text style={styles.smallLabel}>Remplacer</Text>
                  </Pressable>
                  <Pressable onPress={() => undo(item)} hitSlop={6} style={({ pressed }) => [styles.smallBtn, styles.smallBtnDanger, pressed && { opacity: 0.7 }]}>
                    <Text style={[styles.smallLabel, { color: COLORS.error }]}>Annuler</Text>
                  </Pressable>
                </View>
              )}
            </View>
          );
        }}
      />
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  root: { flex: 1, backgroundColor: COLORS.background },
  header: { paddingHorizontal: SPACING.md, paddingTop: SPACING.md, paddingBottom: 4 },
  counter: { fontSize: 14, color: COLORS.textMuted },
  counterNumber: { fontSize: 20, fontWeight: '800', color: COLORS.success },
  searchWrap: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 8,
    margin: SPACING.md,
    marginBottom: 6,
    paddingHorizontal: 12,
    borderRadius: RADIUS.md,
    borderWidth: 1,
    borderColor: COLORS.border,
    backgroundColor: COLORS.surface,
  },
  search: { flex: 1, paddingVertical: 11, fontSize: 15, color: COLORS.text },
  filters: { flexDirection: 'row', flexWrap: 'wrap', gap: 6, marginHorizontal: SPACING.md, marginBottom: 6 },
  chip: {
    paddingHorizontal: 12,
    paddingVertical: 7,
    borderRadius: RADIUS.full,
    borderWidth: 1,
    borderColor: COLORS.border,
    backgroundColor: COLORS.surface,
  },
  chipActive: { backgroundColor: COLORS.brandNavy, borderColor: COLORS.brandNavy },
  chipLabel: { fontSize: 12, fontWeight: '600', color: COLORS.text },
  chipLabelActive: { color: '#fff' },
  listContent: { paddingBottom: SPACING.xxl },
  sectionHeader: { backgroundColor: COLORS.background, paddingHorizontal: SPACING.md, paddingVertical: 4 },
  sectionLetter: { fontSize: 13, fontWeight: '800', color: COLORS.secondaryDark },
  row: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 12,
    backgroundColor: COLORS.surface,
    paddingHorizontal: SPACING.md,
    paddingVertical: 10,
    minHeight: 58,
    borderBottomWidth: StyleSheet.hairlineWidth,
    borderBottomColor: COLORS.border,
  },
  name: { fontSize: 15, color: COLORS.text },
  nom: { fontWeight: '700' },
  cat: { fontSize: 12, color: COLORS.textSubtle },
  done: { fontSize: 12, color: '#166534', marginTop: 2 },
  giveBtn: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 4,
    minHeight: 42,
    paddingHorizontal: 14,
    borderRadius: RADIUS.md,
    backgroundColor: COLORS.success,
  },
  giveLabel: { color: '#fff', fontSize: 14, fontWeight: '700' },
  actions: { flexDirection: 'row', gap: 6 },
  smallBtn: {
    paddingHorizontal: 10,
    paddingVertical: 8,
    borderRadius: RADIUS.sm,
    borderWidth: 1,
    borderColor: COLORS.border,
    backgroundColor: COLORS.surface,
  },
  smallBtnDanger: { borderColor: '#fecaca' },
  smallLabel: { fontSize: 12, fontWeight: '700', color: COLORS.secondaryDark },
});
