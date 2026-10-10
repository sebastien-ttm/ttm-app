import Ionicons from '@expo/vector-icons/Ionicons';
import { Redirect, Stack, useLocalSearchParams } from 'expo-router';
import { useCallback, useEffect, useMemo, useState } from 'react';
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
import type { CheckInRow, CheckInSheet, CheckInVote } from '@/api/types';
import { useAuth } from '@/auth/AuthContext';
import { formatEventWhen } from '@/components/gestion/EmargementTab';
import { ErrorState, FullScreenLoading } from '@/components/Loading';
import { COLORS, RADIUS, SPACING } from '@/config';
import { isStaffMember } from '@/utils/profile';

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

/**
 * Feuille d'émargement d'un événement soumis au vote (espace Staff) : un appui
 * sur un adhérent coche / décoche sa présence, immédiatement enregistrée. Part
 * des votes de présence ; les adhérents qui n'ont pas voté n'apparaissent qu'en
 * recherche (ou une fois émargés) pour ne pas noyer la liste.
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

  const sections = useMemo(() => {
    if (!sheet) return [];
    const q = normalize(query.trim());
    const rows = sheet.data.filter((r) => {
      if (q !== '') return normalize(`${r.nom} ${r.prenom}`).includes(q) || normalize(`${r.prenom} ${r.nom}`).includes(q);
      // Sans recherche : les non-votants ne sont affichés que s'ils sont déjà émargés.
      return r.vote !== 'none' || r.checked;
    });
    return VOTE_ORDER
      .map((vote) => ({ vote, title: VOTE_TITLES[vote], data: rows.filter((r) => r.vote === vote) }))
      .filter((s) => s.data.length > 0);
  }, [sheet, query]);

  if (!isStaffMember(user)) {
    return <Redirect href="/(tabs)" />;
  }

  async function toggle(row: CheckInRow) {
    if (pending.has(row.id) || !sheet) return;
    const next = !row.checked;
    setPending((p) => new Set(p).add(row.id));
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
          {formatEventWhen(sheet.event.startsAt, sheet.event.isAllDay)}
          {sheet.event.location ? ` · ${sheet.event.location}` : ''}
        </Text>
        <Text style={styles.counter}>
          <Text style={styles.counterNumber}>{sheet.checkedCount}</Text> émargé{sheet.checkedCount > 1 ? 's' : ''}
        </Text>
      </View>

      <View style={styles.searchWrap}>
        <Ionicons name="search" size={18} color={COLORS.textMuted} />
        <TextInput
          value={query}
          onChangeText={setQuery}
          placeholder="Chercher un adhérent (même sans vote)"
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

      <SectionList
        sections={sections}
        keyExtractor={(r) => String(r.id)}
        stickySectionHeadersEnabled
        keyboardShouldPersistTaps="handled"
        contentContainerStyle={styles.listContent}
        refreshControl={<RefreshControl refreshing={refreshing} onRefresh={onRefresh} tintColor={COLORS.primary} />}
        ListEmptyComponent={
          <Text style={styles.empty}>
            {query === '' ? 'Personne n\'a voté pour l\'instant : cherchez un adhérent pour l\'émarger.' : 'Aucun adhérent trouvé.'}
          </Text>
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
            style={({ pressed }) => [styles.row, item.checked && styles.rowChecked, pressed && { opacity: 0.75 }]}
          >
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
  counter: { fontSize: 14, color: COLORS.textMuted, marginTop: 4 },
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
  empty: { textAlign: 'center', color: COLORS.textMuted, padding: SPACING.xl, fontSize: 14 },
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
  name: { fontSize: 16, color: COLORS.text },
  nom: { fontWeight: '700' },
  checkedInfo: { fontSize: 12, color: '#166534', marginTop: 2 },
});
