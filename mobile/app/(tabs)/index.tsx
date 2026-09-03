import Ionicons from '@expo/vector-icons/Ionicons';
import { useCallback, useEffect, useRef, useState } from 'react';
import { ActivityIndicator, FlatList, RefreshControl, StyleSheet, Text, View } from 'react-native';

import { ApiError } from '@/api/client';
import { articles as articlesApi } from '@/api/resources';
import type { Article } from '@/api/types';
import { useAuth } from '@/auth/AuthContext';
import { ArticleCard } from '@/components/ArticleCard';
import { BannerImage } from '@/components/BannerImage';
import { EmptyState, ErrorState, FullScreenLoading } from '@/components/Loading';
import { UpcomingEvents } from '@/components/UpcomingEvents';
import { COLORS, SPACING } from '@/config';

const PAGE_SIZE = 20;

export default function FeedScreen() {
  const { user } = useAuth();
  const [items, setItems] = useState<Article[]>([]);
  const [page, setPage] = useState(1);
  const [totalPages, setTotalPages] = useState(1);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [loadingMore, setLoadingMore] = useState(false);
  const [error, setError] = useState<string | null>(null);

  /** Anti-doublon : empêche onEndReached de se déclencher en boucle. */
  const fetchingRef = useRef(false);

  const fetchPage = useCallback(async (pageToLoad: number, mode: 'replace' | 'append'): Promise<void> => {
    if (fetchingRef.current) return;
    fetchingRef.current = true;
    try {
      const resp = await articlesApi.list(pageToLoad);
      setItems((prev) => (mode === 'replace' ? resp.data : [...prev, ...resp.data]));
      setPage(resp.page);
      // Le backend renvoie totalPages (cf. ArticleController.list) — fallback prudent
      setTotalPages(resp.totalPages ?? Math.max(1, Math.ceil(resp.total / (resp.limit || PAGE_SIZE))));
      setError(null);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Erreur de chargement');
    } finally {
      fetchingRef.current = false;
    }
  }, []);

  // Chargement initial (+ rechargement au switch de compte lié — l'audience
  // des articles dépend du profil).
  useEffect(() => {
    let cancelled = false;
    setLoading(true);
    setItems([]);
    (async () => {
      await fetchPage(1, 'replace');
      if (!cancelled) setLoading(false);
    })();
    return () => {
      cancelled = true;
    };
  }, [fetchPage, user?.id]);

  const onRefresh = useCallback(async () => {
    setRefreshing(true);
    await fetchPage(1, 'replace');
    setRefreshing(false);
  }, [fetchPage]);

  const onEndReached = useCallback(async () => {
    if (loadingMore || fetchingRef.current) return;
    if (page >= totalPages) return; // plus rien à charger
    setLoadingMore(true);
    await fetchPage(page + 1, 'append');
    setLoadingMore(false);
  }, [page, totalPages, loadingMore, fetchPage]);

  if (loading) return <FullScreenLoading />;

  return (
    <View style={styles.container}>
      <FlatList
        data={items}
        keyExtractor={(item) => String(item.id)}
        renderItem={({ item }) => <ArticleCard article={item} />}
        ListHeaderComponent={
          <View>
            <BannerImage />
            <UpcomingEvents />
            <View style={styles.sectionHeader}>
              <Ionicons name="newspaper" size={18} color={COLORS.primary} />
              <Text style={styles.sectionTitle}>Articles</Text>
            </View>
          </View>
        }
        contentContainerStyle={styles.content}
        refreshControl={<RefreshControl refreshing={refreshing} onRefresh={onRefresh} tintColor={COLORS.primary} />}
        onEndReached={onEndReached}
        onEndReachedThreshold={0.5}
        ListFooterComponent={
          loadingMore ? (
            <View style={styles.footer}>
              <ActivityIndicator color={COLORS.primary} />
            </View>
          ) : page >= totalPages && items.length > 0 ? (
            <Text style={styles.endLabel}>Vous avez vu tous les articles 🎉</Text>
          ) : null
        }
        ListEmptyComponent={
          error ? (
            <ErrorState message={error} onRetry={() => fetchPage(1, 'replace')} />
          ) : (
            <EmptyState icon="📰" title="Pas encore d'articles" message="Les actualités du club s'afficheront ici." />
          )
        }
      />
    </View>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: COLORS.background },
  content: { paddingBottom: 24 },
  footer: { paddingVertical: 16, alignItems: 'center' },
  endLabel: { textAlign: 'center', color: COLORS.textMuted, paddingVertical: 20, fontSize: 13 },
  sectionHeader: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 6,
    // marginHorizontal = SPACING.md * 2 pour aligner l'icône « Articles »
    // avec l'icône « Prochainement » — celle-ci est décalée à la fois
    // par le margin du card wrapper (SPACING.md) ET par le padding
    // interne (SPACING.md). Sans ça, les deux icônes ne se trouvent
    // pas sur le même axe vertical (visible sur la colonne de gauche).
    marginHorizontal: SPACING.md * 2,
    marginTop: SPACING.md,
    marginBottom: SPACING.sm,
    minHeight: 18,
  },
  sectionTitle: {
    fontSize: 13,
    fontWeight: '700',
    color: COLORS.textMuted,
    textTransform: 'uppercase',
    letterSpacing: 0.5,
    // lineHeight = taille de l'icône : force le texte à occuper la
    // même hauteur que le glyphe, sinon le centrage (alignItems:center)
    // varie selon la métrique du glyphe Ionicons choisi.
    lineHeight: 18,
  },
});
