import Ionicons from '@expo/vector-icons/Ionicons';
import { Stack, useFocusEffect, useRouter } from 'expo-router';
import { useCallback, useState } from 'react';
import { Alert, FlatList, Platform, Pressable, RefreshControl, StyleSheet, Text, View } from 'react-native';

import { ApiError } from '@/api/client';
import { bibs as bibsApi, marketplace as marketplaceApi } from '@/api/resources';
import type { BibOffer, MarketplaceConversationSummary } from '@/api/types';
import { bibPriceLabel } from '@/components/BibForm';
import { formatIsoDate } from '@/components/DateField';
import { ErrorState } from '@/components/Loading';
import { COLORS, RADIUS, SPACING } from '@/config';
import { useAuth } from '@/auth/AuthContext';
import { useRefreshOnResume } from '@/lib/useRefreshOnResume';
import { markSeen } from '@/lib/seenListings';
import { formatRelativeFr } from '@/utils/html';

type Tab = 'browse' | 'mine' | 'messages';

/**
 * Bourse aux dossards : onglet « Dossards » (offres publiées pour des
 * courses à venir, la plus proche d'abord), « Mes dossards » (gestion :
 * modifier / pause-publier / supprimer) et « Messages » (discussions
 * portant sur des dossards). Calquée sur la bourse aux équipements.
 */
export default function BibsScreen() {
  const router = useRouter();
  const { user } = useAuth();
  const [tab, setTab] = useState<Tab>('browse');
  const [offers, setOffers] = useState<BibOffer[]>([]);
  const [mine, setMine] = useState<BibOffer[]>([]);
  const [conversations, setConversations] = useState<MarketplaceConversationSummary[]>([]);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [busyId, setBusyId] = useState<number | null>(null);

  const load = useCallback(async () => {
    try {
      setError(null);
      const [b, m, c] = await Promise.all([bibsApi.list(), bibsApi.mine(), marketplaceApi.conversations()]);
      setOffers(b.data);
      // Liste affichée → plus de pastille « nouveautés » dans Social.
      if (user) void markSeen('bibs', user.id, b.data.map((o) => o.id));
      setMine(m.data);
      setConversations(c.data.filter((conv) => conv.kind === 'bib'));
    } catch (e) {
      setError(e instanceof ApiError ? e.message : 'Erreur de chargement');
    } finally {
      setLoading(false);
      setRefreshing(false);
    }
  }, [user]);

  useFocusEffect(useCallback(() => { void load(); }, [load]));
  useRefreshOnResume(() => { void load(); });

  async function togglePause(item: BibOffer) {
    setBusyId(item.id);
    try {
      if (item.paused) await bibsApi.publish(item.id);
      else await bibsApi.pause(item.id);
      await load();
    } catch (e) {
      showError(e);
    } finally {
      setBusyId(null);
    }
  }

  async function remove(item: BibOffer) {
    const confirmed = await confirmAsync(
      'Supprimer cette offre ?',
      `L'offre de dossards pour « ${item.raceName} » et ses discussions seront définitivement supprimées.`,
    );
    if (!confirmed) return;
    setBusyId(item.id);
    try {
      await bibsApi.remove(item.id);
      await load();
    } catch (e) {
      showError(e);
    } finally {
      setBusyId(null);
    }
  }

  const refreshControl = (
    <RefreshControl refreshing={refreshing} onRefresh={() => { setRefreshing(true); void load(); }} />
  );

  return (
    <View style={styles.container}>
      <Stack.Screen options={{ title: 'Bourse aux dossards' }} />

      <View style={styles.tabs}>
        <TabBtn label="Dossards" count={offers.length} active={tab === 'browse'} onPress={() => setTab('browse')} />
        <TabBtn label="Mes dossards" count={mine.length} active={tab === 'mine'} onPress={() => setTab('mine')} />
        <TabBtn label="Messages" count={conversations.length} active={tab === 'messages'} onPress={() => setTab('messages')} />
      </View>

      <Pressable style={styles.newButton} onPress={() => router.push('/bibs/new' as never)}>
        <Ionicons name="add-circle" size={20} color="#fff" />
        <Text style={styles.newButtonLabel}>Proposer des dossards</Text>
      </Pressable>

      {error ? (
        <ErrorState message={error} onRetry={load} />
      ) : tab === 'browse' ? (
        <FlatList
          data={offers}
          keyExtractor={(item) => 'b-' + item.id}
          contentContainerStyle={styles.list}
          refreshControl={refreshControl}
          renderItem={({ item }) => (
            <Pressable
              onPress={() => router.push(('/bibs/' + item.id) as never)}
              style={({ pressed }) => [styles.card, styles.cardRow, pressed && { opacity: 0.85 }]}
            >
              <BibThumb offer={item} />
              <View style={{ flex: 1 }}>
                <Text style={styles.cardTitle} numberOfLines={2}>{item.raceName}</Text>
                <Text style={styles.cardDate}>{formatIsoDate(item.raceDate)}</Text>
                <PriceBadge offer={item} />
                <Text style={styles.cardMeta}>par {item.authorFullName} · {formatRelativeFr(item.createdAt)}</Text>
              </View>
              <Ionicons name="chevron-forward" size={20} color={COLORS.textMuted} />
            </Pressable>
          )}
          ListEmptyComponent={
            !loading ? (
              <EmptyCard icon="ticket-outline" label="Aucun dossard proposé pour le moment." />
            ) : null
          }
        />
      ) : tab === 'messages' ? (
        <FlatList
          data={conversations}
          keyExtractor={(item) => 'c-' + item.id}
          contentContainerStyle={styles.list}
          refreshControl={refreshControl}
          renderItem={({ item }) => (
            <Pressable
              onPress={() => router.push(('/marketplace/conversation/' + item.id) as never)}
              style={({ pressed }) => [styles.card, styles.cardRow, pressed && { opacity: 0.85 }]}
            >
              <View style={[styles.thumb, styles.thumbNeutral]}>
                <Text style={styles.thumbEmoji}>🎫</Text>
              </View>
              <View style={{ flex: 1 }}>
                <Text style={styles.cardTitle} numberOfLines={1}>
                  {item.otherFullName}
                  <Text style={styles.cardMeta}>
                    {item.iAmSeller ? ' · intéressé(e) par vos dossards' : ' · vendeur'}
                  </Text>
                </Text>
                <Text style={styles.cardMeta} numberOfLines={1}>« {item.listingTitle} »</Text>
                {item.lastMessage && (
                  <Text style={styles.convPreview} numberOfLines={2}>
                    {item.lastMessage.mine ? 'Vous : ' : ''}{item.lastMessage.content}
                  </Text>
                )}
              </View>
              <Text style={styles.convTime}>{formatRelativeFr(item.lastMessageAt)}</Text>
            </Pressable>
          )}
          ListEmptyComponent={
            !loading ? (
              <EmptyCard
                icon="chatbubbles-outline"
                label="Aucune discussion pour le moment. Ouvrez une offre pour écrire à son auteur."
              />
            ) : null
          }
        />
      ) : (
        <FlatList
          data={mine}
          keyExtractor={(item) => 'm-' + item.id}
          contentContainerStyle={styles.list}
          refreshControl={refreshControl}
          renderItem={({ item }) => (
            <View style={[styles.card, { opacity: item.past ? 0.6 : 1 }]}>
              <Pressable
                onPress={() => router.push(('/bibs/' + item.id) as never)}
                style={({ pressed }) => [styles.cardRow, pressed && { opacity: 0.85 }]}
              >
                <BibThumb offer={item} />
                <View style={{ flex: 1 }}>
                  <Text style={styles.cardTitle} numberOfLines={2}>{item.raceName}</Text>
                  <Text style={styles.cardDate}>{formatIsoDate(item.raceDate)}</Text>
                  <View style={[styles.statusBadge, (item.paused || item.past) && styles.statusBadgePaused]}>
                    <Text style={[styles.statusBadgeLabel, (item.paused || item.past) && styles.statusBadgeLabelPaused]}>
                      {item.past ? 'Course passée' : item.paused ? 'En pause' : 'Publiée'}
                    </Text>
                  </View>
                </View>
              </Pressable>
              <View style={styles.cardActions}>
                {!item.past && (
                  <ActionBtn
                    icon="create-outline"
                    label="Modifier"
                    onPress={() => router.push(('/bibs/' + item.id + '/edit') as never)}
                    disabled={busyId === item.id}
                  />
                )}
                {!item.past && (
                  <ActionBtn
                    icon={item.paused ? 'play-outline' : 'pause-outline'}
                    label={item.paused ? 'Publier' : 'Mettre en pause'}
                    onPress={() => void togglePause(item)}
                    disabled={busyId === item.id}
                  />
                )}
                <ActionBtn
                  icon="trash-outline"
                  label="Supprimer"
                  onPress={() => void remove(item)}
                  disabled={busyId === item.id}
                  danger
                />
              </View>
            </View>
          )}
          ListEmptyComponent={
            !loading ? (
              <EmptyCard icon="ticket-outline" label="Vous n'avez pas encore proposé de dossard." />
            ) : null
          }
        />
      )}
    </View>
  );
}

/** Vignette : nombre de dossards sur fond vert (don) ou bleu (revente). */
function BibThumb({ offer, size = 64 }: { offer: BibOffer; size?: number }) {
  const bg = offer.exchangeType === 'don' ? '#16a34a' : '#1d4ed8';
  return (
    <View style={[styles.thumb, { backgroundColor: bg, width: size, height: size }]}>
      <Text style={styles.thumbEmoji}>🎫</Text>
      <Text style={styles.thumbCount}>× {offer.quantity}</Text>
    </View>
  );
}

function PriceBadge({ offer }: { offer: BibOffer }) {
  const isDon = offer.exchangeType === 'don';
  return (
    <View style={[styles.priceBadge, isDon ? styles.priceBadgeDon : styles.priceBadgeSale]}>
      <Text style={[styles.priceBadgeLabel, isDon ? styles.priceBadgeLabelDon : styles.priceBadgeLabelSale]}>
        {bibPriceLabel(offer)}
      </Text>
    </View>
  );
}

function TabBtn({ label, count, active, onPress }: { label: string; count: number; active: boolean; onPress: () => void }) {
  return (
    <Pressable onPress={onPress} style={[styles.tab, active && styles.tabActive]}>
      <Text style={[styles.tabLabel, active && styles.tabLabelActive]} numberOfLines={1}>
        {label}{count > 0 ? ' · ' + count : ''}
      </Text>
    </Pressable>
  );
}

function EmptyCard({ icon, label }: { icon: keyof typeof Ionicons.glyphMap; label: string }) {
  return (
    <View style={styles.emptyCard}>
      <Ionicons name={icon} size={32} color={COLORS.textMuted} />
      <Text style={styles.emptyLabel}>{label}</Text>
    </View>
  );
}

function ActionBtn({ icon, label, onPress, disabled, danger }: {
  icon: keyof typeof Ionicons.glyphMap;
  label: string;
  onPress: () => void;
  disabled?: boolean;
  danger?: boolean;
}) {
  return (
    <Pressable
      onPress={onPress}
      disabled={disabled}
      style={({ pressed }) => [styles.actionBtn, pressed && { opacity: 0.6 }, disabled && { opacity: 0.4 }]}
    >
      <Ionicons name={icon} size={16} color={danger ? COLORS.error : COLORS.textMuted} />
      <Text style={[styles.actionBtnLabel, danger && { color: COLORS.error }]}>{label}</Text>
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
    flex: 1, paddingVertical: 10, paddingHorizontal: 4, borderRadius: RADIUS.md,
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
  thumb: {
    width: 64, height: 64, borderRadius: RADIUS.sm,
    alignItems: 'center', justifyContent: 'center',
  },
  thumbNeutral: { backgroundColor: COLORS.background },
  thumbEmoji: { fontSize: 24 },
  thumbCount: { color: '#fff', fontSize: 13, fontWeight: '800', marginTop: 1 },
  cardTitle: { fontSize: 15, fontWeight: '700', color: COLORS.text },
  cardDate: { fontSize: 13, fontWeight: '600', color: COLORS.text, marginTop: 2 },
  cardMeta: { fontSize: 12, color: COLORS.textMuted, marginTop: 4 },
  priceBadge: { alignSelf: 'flex-start', marginTop: 6, paddingHorizontal: 8, paddingVertical: 2, borderRadius: 10 },
  priceBadgeDon: { backgroundColor: '#dcfce7' },
  priceBadgeSale: { backgroundColor: '#dbeafe' },
  priceBadgeLabel: { fontSize: 11, fontWeight: '700' },
  priceBadgeLabelDon: { color: '#15803d' },
  priceBadgeLabelSale: { color: '#1e40af' },
  convPreview: { fontSize: 13, color: COLORS.text, marginTop: 4 },
  convTime: { fontSize: 11, color: COLORS.textMuted, alignSelf: 'flex-start' },
  statusBadge: {
    alignSelf: 'flex-start', marginTop: 6,
    backgroundColor: '#ecfdf5', paddingHorizontal: 8, paddingVertical: 2, borderRadius: 10,
  },
  statusBadgePaused: { backgroundColor: '#fef3c7' },
  statusBadgeLabel: { fontSize: 11, fontWeight: '700', color: '#047857' },
  statusBadgeLabelPaused: { color: '#92400e' },
  cardActions: { flexDirection: 'row', borderTopWidth: 1, borderTopColor: COLORS.border },
  actionBtn: {
    flex: 1, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 6,
    paddingVertical: 10,
  },
  actionBtnLabel: { fontSize: 12, fontWeight: '600', color: COLORS.textMuted },
  emptyCard: {
    alignItems: 'center', gap: 8, padding: SPACING.xl,
    backgroundColor: COLORS.surface, borderRadius: RADIUS.md,
  },
  emptyLabel: { color: COLORS.textMuted, fontSize: 14, textAlign: 'center' },
});
