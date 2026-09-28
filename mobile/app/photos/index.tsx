import Ionicons from '@expo/vector-icons/Ionicons';
import { Image } from 'expo-image';
import { Stack, useFocusEffect, useRouter } from 'expo-router';
import { useCallback, useState } from 'react';
import { FlatList, Pressable, RefreshControl, StyleSheet, Text, View, useWindowDimensions } from 'react-native';

import { ApiError } from '@/api/client';
import { photos as photosApi } from '@/api/resources';
import type { PhotoAlbum } from '@/api/types';
import { ErrorState } from '@/components/Loading';
import { COLORS, RADIUS, SPACING } from '@/config';
import { useAuth } from '@/auth/AuthContext';
import { usePhotoUrls } from '@/lib/photoUrls';
import { markSeen } from '@/lib/seenListings';
import { useRefreshOnResume } from '@/lib/useRefreshOnResume';

/**
 * Photos du club : albums de la galerie Piwigo (réservés aux adhérents),
 * le plus récent d'abord. Tout adhérent peut créer un album et y
 * ajouter ses photos (compétitions, sorties groupées…).
 */
export default function PhotoAlbumsScreen() {
  const router = useRouter();
  const { user } = useAuth();
  const { width } = useWindowDimensions();
  const { url, reload: reloadToken } = usePhotoUrls();
  const [albums, setAlbums] = useState<PhotoAlbum[]>([]);
  const [enabled, setEnabled] = useState(true);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    try {
      setError(null);
      const resp = await photosApi.albums();
      setEnabled(resp.enabled);
      setAlbums(resp.data);
      // Liste affichée → plus de pastille « nouveaux albums » dans Social.
      if (user) void markSeen('photos', user.id, resp.data.map((a) => a.id));
      await reloadToken();
    } catch (e) {
      setError(e instanceof ApiError ? e.message : 'Erreur de chargement');
    } finally {
      setLoading(false);
      setRefreshing(false);
    }
  }, [reloadToken, user]);

  useFocusEffect(useCallback(() => { void load(); }, [load]));
  useRefreshOnResume(() => { void load(); });

  // 2 colonnes, largeur utile plafonnée comme les autres listes.
  const contentWidth = Math.min(width, 640) - SPACING.md * 2;
  const cardWidth = (contentWidth - SPACING.sm) / 2;

  return (
    <View style={styles.container}>
      <Stack.Screen options={{ title: 'Photos du club' }} />

      {!enabled ? (
        <View style={styles.emptyCard}>
          <Ionicons name="images-outline" size={32} color={COLORS.textMuted} />
          <Text style={styles.emptyLabel}>La galerie photos n'est pas encore ouverte. Revenez bientôt !</Text>
        </View>
      ) : error ? (
        <ErrorState message={error} onRetry={load} />
      ) : (
        <FlatList
          data={albums}
          keyExtractor={(a) => String(a.id)}
          numColumns={2}
          columnWrapperStyle={{ gap: SPACING.sm }}
          contentContainerStyle={styles.list}
          refreshControl={<RefreshControl refreshing={refreshing} onRefresh={() => { setRefreshing(true); void load(); }} />}
          ListHeaderComponent={
            <Pressable style={styles.newButton} onPress={() => router.push('/photos/new' as never)}>
              <Ionicons name="add-circle" size={20} color="#fff" />
              <Text style={styles.newButtonLabel}>Créer un album</Text>
            </Pressable>
          }
          renderItem={({ item }) => {
            const cover = item.coverImageId !== null ? url(item.coverImageId, 'grid') : null;
            return (
              <Pressable
                onPress={() => router.push(('/photos/' + item.id) as never)}
                style={({ pressed }) => [styles.card, { width: cardWidth }, pressed && { opacity: 0.85 }]}
              >
                {cover ? (
                  <Image source={{ uri: cover }} style={[styles.cover, { height: cardWidth * 0.75 }]} contentFit="cover" transition={150} />
                ) : (
                  <View style={[styles.cover, styles.coverEmpty, { height: cardWidth * 0.75 }]}>
                    <Ionicons name="images-outline" size={28} color={COLORS.textMuted} />
                  </View>
                )}
                <View style={styles.cardBody}>
                  <Text style={styles.cardTitle} numberOfLines={2}>{item.name}</Text>
                  <Text style={styles.cardMeta}>
                    {item.nbImages} photo{item.nbImages > 1 ? 's' : ''}
                    {item.dateLast ? ' · ' + formatPiwigoDate(item.dateLast) : ''}
                  </Text>
                </View>
              </Pressable>
            );
          }}
          ListEmptyComponent={
            !loading ? (
              <View style={styles.emptyCard}>
                <Ionicons name="images-outline" size={32} color={COLORS.textMuted} />
                <Text style={styles.emptyLabel}>
                  Aucun album pour le moment. Créez le premier après votre prochaine compétition ou sortie !
                </Text>
              </View>
            ) : null
          }
        />
      )}
    </View>
  );
}

/** « 2026-10-05 14:32:10 » → « 5 oct. 2026 ». */
function formatPiwigoDate(s: string): string {
  const [datePart] = s.split(' ');
  const [y, m, d] = datePart.split('-').map(Number);
  if (!y || !m || !d) return '';
  return new Date(y, m - 1, d).toLocaleDateString('fr-FR', { day: 'numeric', month: 'short', year: 'numeric' });
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: COLORS.background },
  list: { padding: SPACING.md, gap: SPACING.sm, maxWidth: 640, width: '100%', alignSelf: 'center' },
  newButton: {
    flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 8,
    backgroundColor: COLORS.secondary, paddingVertical: 12, borderRadius: RADIUS.md,
    marginBottom: SPACING.sm,
  },
  newButtonLabel: { color: '#fff', fontWeight: '700', fontSize: 14 },
  card: {
    backgroundColor: COLORS.surface, borderRadius: RADIUS.md,
    borderWidth: 1, borderColor: COLORS.border, overflow: 'hidden',
  },
  cover: { width: '100%', backgroundColor: COLORS.background },
  coverEmpty: { alignItems: 'center', justifyContent: 'center' },
  cardBody: { padding: SPACING.sm },
  cardTitle: { fontSize: 14, fontWeight: '700', color: COLORS.text },
  cardMeta: { fontSize: 12, color: COLORS.textMuted, marginTop: 2 },
  emptyCard: {
    alignItems: 'center', gap: 8, padding: SPACING.xl, margin: SPACING.md,
    backgroundColor: COLORS.surface, borderRadius: RADIUS.md,
  },
  emptyLabel: { color: COLORS.textMuted, fontSize: 14, textAlign: 'center' },
});
