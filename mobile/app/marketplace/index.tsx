import Ionicons from '@expo/vector-icons/Ionicons';
import { Image } from 'expo-image';
import { Stack, useFocusEffect, useRouter } from 'expo-router';
import { useCallback, useState } from 'react';
import { Alert, FlatList, Platform, Pressable, RefreshControl, StyleSheet, Text, View } from 'react-native';

import { ApiError } from '@/api/client';
import { marketplace as marketplaceApi } from '@/api/resources';
import type { MarketplaceConversationSummary, MarketplaceListing, MarketplaceListingSummary } from '@/api/types';
import { ErrorState } from '@/components/Loading';
import { COLORS, RADIUS, SPACING } from '@/config';
import { useRefreshOnResume } from '@/lib/useRefreshOnResume';
import { formatRelativeFr } from '@/utils/html';

type Tab = 'browse' | 'mine' | 'messages';

/**
 * Bourse aux équipements : onglet « Annonces » (toutes les annonces
 * publiées, plus récentes d'abord) + onglet « Mes annonces » (gestion
 * perso — modifier / mettre en pause-publier / supprimer directement
 * depuis la carte, sans entrer dans le détail).
 */
export default function MarketplaceScreen() {
  const router = useRouter();
  const [tab, setTab] = useState<Tab>('browse');
  const [listings, setListings] = useState<MarketplaceListingSummary[]>([]);
  const [mine, setMine] = useState<MarketplaceListing[]>([]);
  const [conversations, setConversations] = useState<MarketplaceConversationSummary[]>([]);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [busyId, setBusyId] = useState<number | null>(null);

  const load = useCallback(async () => {
    try {
      setError(null);
      const [b, m, c] = await Promise.all([
        marketplaceApi.list(),
        marketplaceApi.mine(),
        marketplaceApi.conversations(),
      ]);
      setListings(b.data);
      setMine(m.data);
      setConversations(c.data);
    } catch (e) {
      setError(e instanceof ApiError ? e.message : 'Erreur de chargement');
    } finally {
      setLoading(false);
      setRefreshing(false);
    }
  }, []);

  useFocusEffect(useCallback(() => { void load(); }, [load]));
  useRefreshOnResume(() => { void load(); });

  async function togglePause(item: MarketplaceListing) {
    setBusyId(item.id);
    try {
      if (item.paused) await marketplaceApi.publish(item.id);
      else await marketplaceApi.pause(item.id);
      await load();
    } catch (e) {
      showError(e);
    } finally {
      setBusyId(null);
    }
  }

  async function remove(item: MarketplaceListing) {
    const confirmed = await confirmAsync(
      'Supprimer cette annonce ?',
      `« ${item.title} » sera définitivement supprimée, avec ses photos.`,
    );
    if (!confirmed) return;
    setBusyId(item.id);
    try {
      await marketplaceApi.remove(item.id);
      await load();
    } catch (e) {
      showError(e);
    } finally {
      setBusyId(null);
    }
  }

  return (
    <View style={styles.container}>
      <Stack.Screen options={{ title: 'Bourse aux équipements' }} />

      <View style={styles.tabs}>
        <Pressable
          onPress={() => setTab('browse')}
          style={[styles.tab, tab === 'browse' && styles.tabActive]}
        >
          <Text style={[styles.tabLabel, tab === 'browse' && styles.tabLabelActive]}>
            Annonces{listings.length > 0 ? ' · ' + listings.length : ''}
          </Text>
        </Pressable>
        <Pressable
          onPress={() => setTab('mine')}
          style={[styles.tab, tab === 'mine' && styles.tabActive]}
        >
          <Text style={[styles.tabLabel, tab === 'mine' && styles.tabLabelActive]}>
            Mes annonces{mine.length > 0 ? ' · ' + mine.length : ''}
          </Text>
        </Pressable>
        <Pressable
          onPress={() => setTab('messages')}
          style={[styles.tab, tab === 'messages' && styles.tabActive]}
        >
          <Text style={[styles.tabLabel, tab === 'messages' && styles.tabLabelActive]}>
            Messages{conversations.length > 0 ? ' · ' + conversations.length : ''}
          </Text>
        </Pressable>
      </View>

      <Pressable
        style={styles.newButton}
        onPress={() => router.push('/marketplace/new' as never)}
      >
        <Ionicons name="add-circle" size={20} color="#fff" />
        <Text style={styles.newButtonLabel}>Nouvelle annonce</Text>
      </Pressable>

      {error ? (
        <ErrorState message={error} onRetry={load} />
      ) : tab === 'browse' ? (
        <FlatList
          data={listings}
          keyExtractor={(item) => 's-' + item.id}
          contentContainerStyle={styles.list}
          refreshControl={<RefreshControl refreshing={refreshing} onRefresh={() => { setRefreshing(true); void load(); }} />}
          renderItem={({ item }) => (
            <Pressable
              onPress={() => router.push(('/marketplace/' + item.id) as never)}
              style={({ pressed }) => [styles.card, pressed && { opacity: 0.85 }]}
            >
              {item.photoUrl ? (
                <Image source={{ uri: item.photoUrl }} style={styles.cardPhoto} contentFit="cover" />
              ) : (
                <View style={[styles.cardPhoto, styles.cardPhotoPlaceholder]}>
                  <Ionicons name="image-outline" size={28} color={COLORS.textMuted} />
                </View>
              )}
              <View style={{ flex: 1 }}>
                <Text style={styles.cardTitle} numberOfLines={2}>{item.title}</Text>
                <Text style={styles.cardMeta}>
                  par {item.authorFirstName} · {formatRelativeFr(item.createdAt)}
                </Text>
              </View>
              <Ionicons name="chevron-forward" size={20} color={COLORS.textMuted} />
            </Pressable>
          )}
          ListEmptyComponent={
            !loading ? (
              <View style={styles.emptyCard}>
                <Ionicons name="pricetags-outline" size={32} color={COLORS.textMuted} />
                <Text style={styles.emptyLabel}>Aucune annonce pour le moment.</Text>
              </View>
            ) : null
          }
        />
      ) : tab === 'messages' ? (
        <FlatList
          data={conversations}
          keyExtractor={(item) => 'c-' + item.id}
          contentContainerStyle={styles.list}
          refreshControl={<RefreshControl refreshing={refreshing} onRefresh={() => { setRefreshing(true); void load(); }} />}
          renderItem={({ item }) => (
            <Pressable
              onPress={() => router.push(('/marketplace/conversation/' + item.id) as never)}
              style={({ pressed }) => [styles.card, styles.cardRow, pressed && { opacity: 0.85 }]}
            >
              {item.listingPhotoUrl ? (
                <Image source={{ uri: item.listingPhotoUrl }} style={styles.cardPhoto} contentFit="cover" />
              ) : (
                <View style={[styles.cardPhoto, styles.cardPhotoPlaceholder]}>
                  <Ionicons name="image-outline" size={28} color={COLORS.textMuted} />
                </View>
              )}
              <View style={{ flex: 1 }}>
                <Text style={styles.cardTitle} numberOfLines={1}>
                  {item.otherFirstName}
                  <Text style={styles.cardMeta}>
                    {item.iAmSeller ? ' · intéressé(e) par votre annonce' : ' · vendeur'}
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
              <View style={styles.emptyCard}>
                <Ionicons name="chatbubbles-outline" size={32} color={COLORS.textMuted} />
                <Text style={styles.emptyLabel}>
                  Aucune discussion pour le moment. Ouvrez une annonce pour écrire à son auteur.
                </Text>
              </View>
            ) : null
          }
        />
      ) : (
        <FlatList
          data={mine}
          keyExtractor={(item) => 'm-' + item.id}
          contentContainerStyle={styles.list}
          refreshControl={<RefreshControl refreshing={refreshing} onRefresh={() => { setRefreshing(true); void load(); }} />}
          renderItem={({ item }) => (
            <View style={[styles.card, styles.cardColumn]}>
              <Pressable
                onPress={() => router.push(('/marketplace/' + item.id) as never)}
                style={({ pressed }) => [styles.cardRow, pressed && { opacity: 0.85 }]}
              >
                {item.photos[0] ? (
                  <Image source={{ uri: item.photos[0].url }} style={styles.cardPhoto} contentFit="cover" />
                ) : (
                  <View style={[styles.cardPhoto, styles.cardPhotoPlaceholder]}>
                    <Ionicons name="image-outline" size={28} color={COLORS.textMuted} />
                  </View>
                )}
                <View style={{ flex: 1 }}>
                  <Text style={styles.cardTitle} numberOfLines={2}>{item.title}</Text>
                  <View style={[styles.statusBadge, item.paused && styles.statusBadgePaused]}>
                    <Text style={[styles.statusBadgeLabel, item.paused && styles.statusBadgeLabelPaused]}>
                      {item.paused ? 'En pause' : 'Publiée'}
                    </Text>
                  </View>
                </View>
              </Pressable>
              <View style={styles.cardActions}>
                <ActionBtn
                  icon="create-outline"
                  label="Modifier"
                  onPress={() => router.push(('/marketplace/' + item.id + '/edit') as never)}
                  disabled={busyId === item.id}
                />
                <ActionBtn
                  icon={item.paused ? 'play-outline' : 'pause-outline'}
                  label={item.paused ? 'Publier' : 'Mettre en pause'}
                  onPress={() => void togglePause(item)}
                  disabled={busyId === item.id}
                />
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
              <View style={styles.emptyCard}>
                <Ionicons name="pricetags-outline" size={32} color={COLORS.textMuted} />
                <Text style={styles.emptyLabel}>Vous n'avez pas encore publié d'annonce.</Text>
              </View>
            ) : null
          }
        />
      )}
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
  cardColumn: { flexDirection: 'column' },
  cardRow: {
    flexDirection: 'row', alignItems: 'center', gap: 12, padding: SPACING.sm,
  },
  cardPhoto: { width: 64, height: 64, borderRadius: RADIUS.sm, backgroundColor: COLORS.background },
  cardPhotoPlaceholder: { alignItems: 'center', justifyContent: 'center' },
  cardTitle: { fontSize: 15, fontWeight: '700', color: COLORS.text },
  cardMeta: { fontSize: 12, color: COLORS.textMuted, marginTop: 4 },
  convPreview: { fontSize: 13, color: COLORS.text, marginTop: 4 },
  convTime: { fontSize: 11, color: COLORS.textMuted, alignSelf: 'flex-start' },
  statusBadge: {
    alignSelf: 'flex-start', marginTop: 6,
    backgroundColor: '#ecfdf5', paddingHorizontal: 8, paddingVertical: 2, borderRadius: 10,
  },
  statusBadgePaused: { backgroundColor: '#fef3c7' },
  statusBadgeLabel: { fontSize: 11, fontWeight: '700', color: '#047857' },
  statusBadgeLabelPaused: { color: '#92400e' },
  cardActions: {
    flexDirection: 'row', borderTopWidth: 1, borderTopColor: COLORS.border,
  },
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
