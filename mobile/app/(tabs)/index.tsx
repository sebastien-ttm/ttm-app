import Ionicons from '@expo/vector-icons/Ionicons';
import { useFocusEffect } from 'expo-router';
import { useCallback, useEffect, useRef, useState } from 'react';
import { ActivityIndicator, FlatList, Pressable, RefreshControl, StyleSheet, Text, View } from 'react-native';

import { ApiError } from '@/api/client';
import { articles as articlesApi } from '@/api/resources';
import type { Article } from '@/api/types';
import { useAuth } from '@/auth/AuthContext';
import { ArticleCard } from '@/components/ArticleCard';
import { BannerImage } from '@/components/BannerImage';
import { EmptyState, ErrorState, FullScreenLoading } from '@/components/Loading';
import { MyStagesCard } from '@/components/trips/MyStagesCard';
import { MyTripsCard } from '@/components/trips/MyTripsCard';
import { UpcomingEvents } from '@/components/UpcomingEvents';
import { COLORS, RADIUS, SPACING } from '@/config';
import { useRefreshOnResume } from '@/lib/useRefreshOnResume';

/** Articles affichés d'abord, puis ajoutés à chaque appui sur « Voir les … suivants ». */
const PAGE_SIZE = 5;
/** Plafond de `limit` côté serveur (ArticleController::list) : borne le rechargement d'une liste déjà déroulée. */
const MAX_LIMIT = 50;

export default function FeedScreen() {
  const { user } = useAuth();
  const [items, setItems] = useState<Article[]>([]);
  const [total, setTotal] = useState(0);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [loadingMore, setLoadingMore] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [moreError, setMoreError] = useState(false);

  /** Anti-doublon : empêche deux chargements simultanés. */
  const fetchingRef = useRef(false);
  /** Articles actuellement affichés (copie de `items` lisible par les callbacks stables). */
  const itemsRef = useRef<Article[]>([]);

  /**
   * 'replace' : recharge le début du fil en reprenant autant d'articles qu'il y en a d'affichés (5 au départ, plus si
   * l'adhérent en a déroulé), pour qu'un retour sur l'onglet ne referme pas la liste. 'append' : les 5 suivants.
   */
  const fetchPage = useCallback(async (mode: 'replace' | 'append'): Promise<void> => {
    if (fetchingRef.current) return;
    fetchingRef.current = true;
    try {
      const shown = itemsRef.current.length;
      const resp = mode === 'replace'
        ? await articlesApi.list(1, Math.min(MAX_LIMIT, Math.max(PAGE_SIZE, shown)))
        : await articlesApi.list(Math.floor(shown / PAGE_SIZE) + 1, PAGE_SIZE);
      // Un article publié entre-temps décale les pages : on écarte ceux qu'on affiche déjà.
      const known = new Set(itemsRef.current.map((a) => a.id));
      const next = mode === 'replace' ? resp.data : [...itemsRef.current, ...resp.data.filter((a) => !known.has(a.id))];
      itemsRef.current = next;
      setItems(next);
      setTotal(resp.total);
      setError(null);
      if (mode === 'append') setMoreError(false);
    } catch (err) {
      if (mode === 'append') setMoreError(true);
      else setError(err instanceof ApiError ? err.message : 'Erreur de chargement');
    } finally {
      fetchingRef.current = false;
    }
  }, []);

  // Chargement initial (+ rechargement au switch de compte lié — l'audience
  // des articles dépend du profil).
  useEffect(() => {
    let cancelled = false;
    setLoading(true);
    itemsRef.current = [];
    setItems([]);
    setMoreError(false);
    (async () => {
      await fetchPage('replace');
      if (!cancelled) setLoading(false);
    })();
    return () => {
      cancelled = true;
    };
  }, [fetchPage, user?.id]);

  const onRefresh = useCallback(async () => {
    setRefreshing(true);
    await fetchPage('replace');
    setRefreshing(false);
  }, [fetchPage]);

  // Recharge le fil au retour de background après > 60s d'inactivité —
  // évite d'afficher un feed figé après plusieurs heures/jours en veille.
  useRefreshOnResume(() => { void fetchPage('replace'); });

  // Recharge le fil à chaque fois que l'onglet regagne le focus —
  // couvre le cas « je viens de réagir sur un article, je reviens à la
  // liste, je veux voir le nouveau compteur d'emoji » (ArticleCard est
  // un composant présentationnel qui reflète juste la prop `article`).
  // Silencieux : pas de spinner, on mute le fetch en arrière-plan.
  useFocusEffect(useCallback(() => {
    void fetchPage('replace');
  }, [fetchPage]));

  // L'article qu'on ouvre est marqué lu tout de suite dans la liste (le serveur l'enregistre à l'ouverture,
  // et le rechargement au retour sur l'onglet confirme).
  const markRead = useCallback((opened: Article) => {
    const next = itemsRef.current.map((a) => (a.id === opened.id && !a.read ? { ...a, read: true } : a));
    itemsRef.current = next;
    setItems(next);
  }, []);

  const showMore = useCallback(async () => {
    if (loadingMore || fetchingRef.current) return;
    setLoadingMore(true);
    setMoreError(false);
    await fetchPage('append');
    setLoadingMore(false);
  }, [loadingMore, fetchPage]);

  const remaining = total - items.length;

  if (loading) return <FullScreenLoading />;

  return (
    <View style={styles.container}>
      <FlatList
        data={items}
        keyExtractor={(item) => String(item.id)}
        renderItem={({ item }) => <ArticleCard article={item} onOpen={markRead} />}
        ListHeaderComponent={
          <View>
            {/* BannerImage : temporairement masquée sur mobile.
                Le composant reste importé pour ré-affichage rapide
                (retirer le commentaire ci-dessous). */}
            {/* <BannerImage /> */}
            {/* Mes déplacements (si je participe à un déplacement publié) : accès à sa vue dédiée. */}
            <MyTripsCard />
            {/* Mes stages : idem, vue dédiée avec entraînements, événements, sondages, prises de temps et articles du stage. */}
            <MyStagesCard />
            <UpcomingEvents />
            <View style={styles.sectionHeader}>
              <Ionicons name="newspaper" size={18} color={COLORS.primary} />
              <Text style={styles.sectionTitle}>Articles</Text>
            </View>
          </View>
        }
        contentContainerStyle={styles.content}
        refreshControl={<RefreshControl refreshing={refreshing} onRefresh={onRefresh} tintColor={COLORS.primary} />}
        ListFooterComponent={
          items.length === 0 ? null : remaining > 0 ? (
            <View style={styles.footer}>
              {moreError ? <Text style={styles.moreError}>Chargement impossible. Réessayez.</Text> : null}
              <Pressable
                onPress={() => void showMore()}
                disabled={loadingMore}
                accessibilityRole="button"
                style={({ pressed }) => [styles.moreButton, (pressed || loadingMore) && { opacity: 0.7 }]}
              >
                {loadingMore ? (
                  <ActivityIndicator color={COLORS.primary} />
                ) : (
                  <Text style={styles.moreLabel}>
                    {remaining === 1 ? "Voir l'article suivant" : `Voir les ${Math.min(PAGE_SIZE, remaining)} articles suivants`}
                  </Text>
                )}
              </Pressable>
            </View>
          ) : (
            <Text style={styles.endLabel}>Vous avez vu tous les articles 🎉</Text>
          )
        }
        ListEmptyComponent={
          error ? (
            <ErrorState message={error} onRetry={() => fetchPage('replace')} />
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
  footer: { paddingHorizontal: SPACING.md, paddingVertical: 16, gap: 8, alignItems: 'stretch' },
  moreButton: {
    alignItems: 'center', justifyContent: 'center', minHeight: 44,
    borderRadius: RADIUS.md, borderWidth: 1, borderColor: COLORS.primary, backgroundColor: COLORS.surface,
  },
  moreLabel: { fontSize: 14, fontWeight: '700', color: COLORS.primary },
  moreError: { fontSize: 13, color: COLORS.error, textAlign: 'center' },
  endLabel: { textAlign: 'center', color: COLORS.textMuted, paddingVertical: 20, fontSize: 13 },
  sectionHeader: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 6,
    // Aligné avec les section titles standards (mêmes marges que
    // « Prochainement » et que les article cards en dessous).
    marginHorizontal: SPACING.md,
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
