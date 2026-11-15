import Ionicons from '@expo/vector-icons/Ionicons';
import { Image } from 'expo-image';
import { Stack, useFocusEffect, useRouter } from 'expo-router';
import { useCallback, useMemo, useState } from 'react';
import { Alert, FlatList, Platform, Pressable, RefreshControl, StyleSheet, Text, View } from 'react-native';

import { ApiError } from '@/api/client';
import { marketplace as marketplaceApi } from '@/api/resources';
import type { MarketplaceConversationSummary, MarketplaceListing, MarketplaceListingSummary } from '@/api/types';
import { ErrorState } from '@/components/Loading';
import { COLORS, RADIUS, SPACING } from '@/config';
import { useAuth } from '@/auth/AuthContext';
import { useRefreshOnResume } from '@/lib/useRefreshOnResume';
import { markSeen } from '@/lib/seenListings';
import { formatRelativeFr } from '@/utils/html';
import { formatPrice } from '@/utils/price';

/** « expire dans 6 j », « expire aujourd'hui » : temps restant avant la désactivation automatique de l'annonce. */
function expiresIn(expiresAt: string | undefined): string | null {
  if (!expiresAt) return null;
  const days = Math.ceil((new Date(expiresAt).getTime() - Date.now()) / 86_400_000);
  if (!Number.isFinite(days) || days <= 0) return null;
  return days === 1 ? 'expire demain' : `expire dans ${days} j`;
}

type Tab = 'browse' | 'mine' | 'messages';

/** Ligne de l'onglet « Annonces » : une annonce, ou l'intitulé d'un bloc (annonces que j'aime / autres annonces). */
type Row =
  | { kind: 'header'; key: string; title: string }
  | { kind: 'listing'; key: string; item: MarketplaceListingSummary };

/** Annonce que je garde grâce à mon « J'aime » alors qu'elle n'est plus proposée au club (expirée ou en pause). */
function isInactive(item: { expired?: boolean; paused?: boolean }): boolean {
  return item.expired === true || item.paused === true;
}

/**
 * Bourse aux équipements : onglet « Annonces » (toutes les annonces
 * publiées, plus récentes d'abord) + onglet « Mes annonces » (gestion
 * perso — modifier / mettre en pause-publier / supprimer directement
 * depuis la carte, sans entrer dans le détail).
 *
 * « J'aime » (cœur sur la carte et dans le détail) : l'annonce passe dans un bloc « Annonces que j'aime » en tête de
 * liste et y reste tant que son auteur ne l'a pas supprimée, même après ses 10 jours (elle porte alors le badge
 * « Expirée »).
 */
export default function MarketplaceScreen() {
  const router = useRouter();
  const { user } = useAuth();
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
      // Liste affichée → plus de pastille « nouveautés » dans Social.
      if (user) void markSeen('marketplace', user.id, b.data.map((l) => l.id));
      setMine(m.data);
      // Les discussions sur des dossards vivent dans la bourse aux dossards.
      setConversations(c.data.filter((conv) => conv.kind !== 'bib'));
    } catch (e) {
      setError(e instanceof ApiError ? e.message : 'Erreur de chargement');
    } finally {
      setLoading(false);
      setRefreshing(false);
    }
  }, [user]);

  useFocusEffect(useCallback(() => { void load(); }, [load]));
  useRefreshOnResume(() => { void load(); });

  async function togglePause(item: MarketplaceListing) {
    setBusyId(item.id);
    try {
      // En pause ou expirée : « Publier » / « Réactiver » remet l'annonce dans la liste pour 10 jours.
      if (item.paused || item.expired) await marketplaceApi.publish(item.id);
      else await marketplaceApi.pause(item.id);
      await load();
    } catch (e) {
      showError(e);
    } finally {
      setBusyId(null);
    }
  }

  async function toggleLike(item: MarketplaceListingSummary) {
    const liked = item.liked === true;
    const inactive = isInactive(item);
    // Une annonce expirée ou en pause ne se re-aime pas : la retirer de ses « J'aime » la fait disparaître pour de bon.
    if (liked && inactive) {
      const ok = await confirmAsync(
        'Retirer cette annonce ?',
        `« ${item.title} » n'est plus proposée au club : elle disparaîtra de votre liste.`,
        'Retirer',
      );
      if (!ok) return;
    }
    setBusyId(item.id);
    try {
      if (liked) await marketplaceApi.unlike(item.id);
      else await marketplaceApi.like(item.id);
      setListings((prev) => (liked && inactive
        ? prev.filter((l) => l.id !== item.id)
        : prev.map((l) => (l.id === item.id ? { ...l, liked: !liked } : l))));
    } catch (e) {
      showError(e);
      await load();
    } finally {
      setBusyId(null);
    }
  }

  // Deux blocs quand il y a des deux sortes : les annonces que j'aime d'abord (actives en premier, puis les expirées /
  // en pause que je garde), les autres ensuite. Un seul bloc : pas d'intitulés.
  const rows = useMemo<Row[]>(() => {
    const toRows = (list: MarketplaceListingSummary[]): Row[] => list.map((item) => ({ kind: 'listing', key: 's-' + item.id, item }));
    const liked = listings.filter((l) => l.liked === true);
    const others = listings.filter((l) => l.liked !== true);
    const both = liked.length > 0 && others.length > 0;
    return [
      ...(both ? [{ kind: 'header', key: 'h-liked', title: `❤️ Annonces que j'aime · ${liked.length}` } as Row] : []),
      ...toRows(liked),
      ...(both ? [{ kind: 'header', key: 'h-others', title: `🛍️ Autres annonces · ${others.length}` } as Row] : []),
      ...toRows(others),
    ];
  }, [listings]);

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
          data={rows}
          keyExtractor={(row) => row.key}
          contentContainerStyle={styles.list}
          refreshControl={<RefreshControl refreshing={refreshing} onRefresh={() => { setRefreshing(true); void load(); }} />}
          renderItem={({ item: row }) => {
            if (row.kind === 'header') return <Text style={styles.blockTitle}>{row.title}</Text>;
            const item = row.item;
            // Cœur : pas sur mes propres annonces, ni avec un backend qui ne connaît pas encore les « J'aime ».
            const canLike = item.liked !== undefined && item.authorId !== user?.id;
            return (
              <Pressable
                onPress={() => router.push(('/marketplace/' + item.id) as never)}
                style={({ pressed }) => [styles.card, styles.cardRow, pressed && { opacity: 0.85 }]}
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
                  {formatPrice(item.price) && <Text style={styles.cardPrice}>{formatPrice(item.price)}</Text>}
                  <Text style={styles.cardMeta}>
                    par {item.authorFullName} · {formatRelativeFr(item.publishedAt ?? item.createdAt)}
                  </Text>
                  {isInactive(item) && (
                    <>
                      <View style={[styles.statusBadge, item.expired ? styles.statusBadgeExpired : styles.statusBadgePaused]}>
                        <Text style={[styles.statusBadgeLabel, item.expired ? styles.statusBadgeLabelExpired : styles.statusBadgeLabelPaused]}>
                          {item.expired ? 'Expirée' : 'En pause'}
                        </Text>
                      </View>
                      <Text style={styles.cardMeta}>Plus proposée au club : gardée car vous l'aimez.</Text>
                    </>
                  )}
                </View>
                {canLike && (
                  <Pressable
                    onPress={() => void toggleLike(item)}
                    disabled={busyId === item.id}
                    hitSlop={8}
                    accessibilityRole="button"
                    accessibilityState={{ selected: item.liked === true }}
                    accessibilityLabel={item.liked ? "Retirer des annonces que j'aime" : "J'aime cette annonce"}
                    style={({ pressed }) => [styles.heartBtn, (pressed || busyId === item.id) && { opacity: 0.5 }]}
                  >
                    <Ionicons
                      name={item.liked ? 'heart' : 'heart-outline'}
                      size={26}
                      color={item.liked ? '#dc2626' : COLORS.textMuted}
                    />
                  </Pressable>
                )}
                <Ionicons name="chevron-forward" size={20} color={COLORS.textMuted} />
              </Pressable>
            );
          }}
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
                  {item.otherFullName}
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
                  {formatPrice(item.price) && <Text style={styles.cardPrice}>{formatPrice(item.price)}</Text>}
                  <View style={[styles.statusBadge, item.paused && styles.statusBadgePaused, item.expired && styles.statusBadgeExpired]}>
                    <Text style={[styles.statusBadgeLabel, item.paused && styles.statusBadgeLabelPaused, item.expired && styles.statusBadgeLabelExpired]}>
                      {item.expired ? 'Expirée' : item.paused ? 'En pause' : 'Publiée'}
                    </Text>
                  </View>
                  {item.expired ? (
                    <Text style={styles.cardMeta}>Plus visible du club — réactivez-la pour la remettre dans la liste.</Text>
                  ) : !item.paused && expiresIn(item.expiresAt) ? (
                    <Text style={styles.cardMeta}>{expiresIn(item.expiresAt)}</Text>
                  ) : null}
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
                  icon={item.paused || item.expired ? 'play-outline' : 'pause-outline'}
                  label={item.expired ? 'Réactiver' : item.paused ? 'Publier' : 'Mettre en pause'}
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

function confirmAsync(title: string, message: string, confirmLabel = 'Supprimer'): Promise<boolean> {
  if (Platform.OS === 'web') {
    return Promise.resolve(typeof window !== 'undefined' ? window.confirm(title + '\n' + message) : false);
  }
  return new Promise((resolve) => {
    Alert.alert(title, message, [
      { text: 'Annuler', style: 'cancel', onPress: () => resolve(false) },
      { text: confirmLabel, style: 'destructive', onPress: () => resolve(true) },
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
  blockTitle: { fontSize: 13, fontWeight: '800', color: COLORS.secondaryDark, marginTop: SPACING.xs, marginBottom: SPACING.sm },
  heartBtn: { padding: 2 },
  cardPhoto: { width: 64, height: 64, borderRadius: RADIUS.sm, backgroundColor: COLORS.background },
  cardPhotoPlaceholder: { alignItems: 'center', justifyContent: 'center' },
  cardTitle: { fontSize: 15, fontWeight: '700', color: COLORS.text },
  cardMeta: { fontSize: 12, color: COLORS.textMuted, marginTop: 4 },
  cardPrice: { fontSize: 15, fontWeight: '800', color: COLORS.primary, marginTop: 2 },
  convPreview: { fontSize: 13, color: COLORS.text, marginTop: 4 },
  convTime: { fontSize: 11, color: COLORS.textMuted, alignSelf: 'flex-start' },
  statusBadge: {
    alignSelf: 'flex-start', marginTop: 6,
    backgroundColor: '#ecfdf5', paddingHorizontal: 8, paddingVertical: 2, borderRadius: 10,
  },
  statusBadgePaused: { backgroundColor: '#fef3c7' },
  statusBadgeExpired: { backgroundColor: '#fee2e2' },
  statusBadgeLabel: { fontSize: 11, fontWeight: '700', color: '#047857' },
  statusBadgeLabelPaused: { color: '#92400e' },
  statusBadgeLabelExpired: { color: '#991b1b' },
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
