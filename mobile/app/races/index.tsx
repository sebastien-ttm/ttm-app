import Ionicons from '@expo/vector-icons/Ionicons';
import { Stack, useFocusEffect, useRouter } from 'expo-router';
import { useCallback, useState } from 'react';
import { Alert, FlatList, Platform, Pressable, RefreshControl, StyleSheet, Text, View } from 'react-native';

import { ApiError } from '@/api/client';
import { races as racesApi } from '@/api/resources';
import type { RaceProposal, RaceVote } from '@/api/types';
import { useAuth } from '@/auth/AuthContext';
import { ErrorState } from '@/components/Loading';
import { formatRaceDate, raceTypeMeta } from '@/components/RaceForm';
import { COLORS, RADIUS, SPACING } from '@/config';
import { useRefreshOnResume } from '@/lib/useRefreshOnResume';
import { markSeen } from '@/lib/seenListings';

type Tab = 'browse' | 'mine';

/**
 * Courses proposées par les adhérents (onglet Social) : onglet « À venir »
 * (toutes les propositions, la plus proche d'abord, vote d'intérêt
 * directement depuis la carte) + « Mes propositions » (modifier /
 * supprimer). Même organisation que la bourse aux équipements.
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

  const load = useCallback(async () => {
    try {
      setError(null);
      const resp = await racesApi.list();
      setItems(resp.data);
      // Liste affichée → plus de pastille « nouveautés » dans Social.
      if (user) void markSeen('races', user.id, resp.data.map((r) => r.id));
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

  const data = tab === 'browse' ? items : mine;

  return (
    <View style={styles.container}>
      <Stack.Screen options={{ title: 'Courses proposées' }} />

      <View style={styles.tabs}>
        <Pressable onPress={() => setTab('browse')} style={[styles.tab, tab === 'browse' && styles.tabActive]}>
          <Text style={[styles.tabLabel, tab === 'browse' && styles.tabLabelActive]}>
            À venir{items.length > 0 ? ' · ' + items.length : ''}
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
        <Text style={styles.newButtonLabel}>Proposer une course</Text>
      </Pressable>

      {error ? (
        <ErrorState message={error} onRetry={load} />
      ) : (
        <FlatList
          data={data}
          keyExtractor={(item) => tab + '-' + item.id}
          contentContainerStyle={styles.list}
          refreshControl={<RefreshControl refreshing={refreshing} onRefresh={() => { setRefreshing(true); void load(); }} />}
          renderItem={({ item }) => (
            <RaceCard
              item={item}
              busy={busyId === item.id}
              onOpen={() => router.push(('/races/' + item.id) as never)}
              footer={tab === 'browse' ? (
                <>
                  <ActionBtn
                    icon={item.myVote === 'interested' ? 'thumbs-up' : 'thumbs-up-outline'}
                    label={'Intéressé(e) · ' + item.interestedCount}
                    active={item.myVote === 'interested'}
                    onPress={() => void vote(item, 'interested')}
                    disabled={busyId === item.id}
                  />
                  <ActionBtn
                    icon={item.myVote === 'maybe' ? 'help-circle' : 'help-circle-outline'}
                    label={'Peut-être · ' + item.maybeCount}
                    active={item.myVote === 'maybe'}
                    onPress={() => void vote(item, 'maybe')}
                    disabled={busyId === item.id}
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
          )}
          ListEmptyComponent={
            !loading ? (
              <View style={styles.emptyCard}>
                <Ionicons name="flag-outline" size={32} color={COLORS.textMuted} />
                <Text style={styles.emptyLabel}>
                  {tab === 'browse'
                    ? 'Aucune course proposée pour le moment. Lancez la première !'
                    : "Vous n'avez pas encore proposé de course."}
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
          <Text style={styles.cardDate}>{formatRaceDate(item.raceDate)}</Text>
          <View style={styles.badgeRow}>
            <View style={[styles.typeBadge, { backgroundColor: meta.color }]}>
              <Text style={styles.typeBadgeLabel}>{item.typeLabel}</Text>
            </View>
            {item.captain && (
              <View style={styles.captainBadge}>
                <Text style={styles.captainBadgeLabel}>🧢 Capitaine : {item.authorFirstName}</Text>
              </View>
            )}
          </View>
          {!item.captain && (
            <Text style={styles.cardMeta}>proposée par {item.authorFirstName}</Text>
          )}
        </View>
        <Ionicons name="chevron-forward" size={20} color={COLORS.textMuted} />
      </Pressable>
      <View style={styles.cardActions}>{footer}</View>
    </View>
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
