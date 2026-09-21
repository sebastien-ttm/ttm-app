import Ionicons from '@expo/vector-icons/Ionicons';
import { useLocalSearchParams, useNavigation, useRouter } from 'expo-router';
import { useCallback, useEffect, useState } from 'react';
import { Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';

import { ApiError } from '@/api/client';
import { pages as pagesApi } from '@/api/resources';
import type { StaticPage, StaticPageNode } from '@/api/types';
import { ErrorState, FullScreenLoading } from '@/components/Loading';
import { RichContent } from '@/components/RichContent';
import { ShareButton } from '@/components/ShareButton';
import { useDocumentTitle } from '@/lib/useDocumentTitle';
import { COLORS } from '@/config';
import { htmlToText } from '@/utils/html';

export default function PageScreen() {
  const params = useLocalSearchParams<{ slug: string }>();
  const router = useRouter();
  const navigation = useNavigation();
  // Retour explicite vers l'onglet Club — les pages statiques y sont
  // indexées, c'est le contexte naturel de sortie. On ne passe PAS par
  // router.back() : les changements d'onglet ne sont pas empilés dans
  // l'historique de navigation (semantics `navigate` par défaut sur
  // Tabs), donc back() renverrait sur l'écran root (Actualités) même
  // quand l'user était sur Club juste avant. Cohérent avec le bouton
  // flèche gauche du header (backToPractical).
  const goBack = useCallback(() => {
    router.replace('/(tabs)/practical' as never);
  }, [router]);
  const slug = String(params.slug ?? '');

  const [page, setPage] = useState<StaticPage | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  // Titre dynamique dans le header (compatible Tabs et Stack parents).
  useEffect(() => {
    navigation.setOptions({ title: page?.title ?? '' });
  }, [navigation, page?.title]);

  // Web : document.title + og:title portent le nom de la page.
  useDocumentTitle(page?.title, page ? htmlToText(page.content).slice(0, 160) : null);

  const load = useCallback(async () => {
    if (!slug) return;
    try {
      setError(null);
      const data = await pagesApi.get(slug);
      setPage(data);
    } catch (err) {
      // 403 / 404 → redirection vers l'écran « Contenu non autorisé »
      if (err instanceof ApiError && (err.status === 403 || err.status === 404)) {
        router.replace({
          pathname: '/access-denied',
          params: { reason: err.status === 403 ? 'forbidden' : 'not-found' },
        } as never);
        return;
      }
      setError(err instanceof ApiError ? err.message : 'Page introuvable');
    } finally {
      setLoading(false);
    }
  }, [slug, router]);

  useEffect(() => {
    // Réinitialise l'état à chaque changement de slug pour éviter le
    // flash du contenu précédent pendant le fetch (navigation entre
    // deux pages statiques dans le même composant réutilisé).
    setPage(null);
    setError(null);
    setLoading(true);
    void load();
  }, [slug, load]);

  const text = page ? htmlToText(page.content).trim() : '';
  const hasContent = text.length > 0;
  const hasChildren = (page?.children?.length ?? 0) > 0;

  return (
    <View style={styles.container}>
      {loading ? (
        <FullScreenLoading />
      ) : error ? (
        <ErrorState message={error} onRetry={load} />
      ) : page ? (
        <ScrollView contentContainerStyle={styles.content}>
          <View style={{ flexDirection: 'row', marginBottom: 10 }}>
            <ShareButton path={`/page/${page.slug}`} title={page.title} />
          </View>
          {hasContent && <RichContent html={page.content} style={styles.body} />}

          {hasChildren && (
            <View style={[styles.children, hasContent && styles.childrenSpaced]}>
              <Text style={styles.childrenTitle}>Sous-pages</Text>
              {page.children.map((child) => (
                <ChildLink key={child.slug} node={child} />
              ))}
            </View>
          )}

          {!hasContent && !hasChildren && (
            <Text style={styles.empty}>Cette page est vide pour le moment.</Text>
          )}

          <Pressable onPress={goBack} style={styles.backBtn}>
            <Text style={styles.backBtnLabel}>Retour</Text>
          </Pressable>
        </ScrollView>
      ) : null}
    </View>
  );
}

function ChildLink({ node }: { node: StaticPageNode }) {
  const router = useRouter();
  return (
    <Pressable
      style={({ pressed }) => [styles.childRow, pressed && styles.childRowPressed]}
      onPress={() => router.push(`/page/${node.slug}` as never)}
    >
      <Text style={styles.childLabel}>{node.title}</Text>
      <View style={styles.childMeta}>
        {node.hasChildren && <Text style={styles.childCount}>{node.children.length}</Text>}
        <Ionicons name="chevron-forward" size={18} color={COLORS.textMuted} />
      </View>
    </Pressable>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: COLORS.background },
  content: { padding: 16, paddingBottom: 40 },
  title: { fontSize: 24, fontWeight: '700', color: COLORS.text, marginBottom: 12 },
  body: { fontSize: 15, color: COLORS.text, lineHeight: 24 },
  empty: { fontSize: 14, color: COLORS.textMuted, fontStyle: 'italic' },
  children: { backgroundColor: COLORS.surface, borderRadius: 12, paddingVertical: 4 },
  childrenSpaced: { marginTop: 24 },
  childrenTitle: {
    fontSize: 13,
    fontWeight: '700',
    color: COLORS.textMuted,
    textTransform: 'uppercase',
    letterSpacing: 0.5,
    paddingHorizontal: 14,
    paddingTop: 12,
    paddingBottom: 6,
  },
  childRow: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    paddingVertical: 12,
    paddingHorizontal: 14,
    borderTopWidth: StyleSheet.hairlineWidth,
    borderTopColor: COLORS.border,
  },
  childRowPressed: { backgroundColor: COLORS.background },
  childLabel: { fontSize: 15, color: COLORS.text, flex: 1 },
  childMeta: { flexDirection: 'row', alignItems: 'center', gap: 6 },
  childCount: {
    fontSize: 12,
    color: COLORS.textMuted,
    backgroundColor: COLORS.background,
    paddingHorizontal: 8,
    paddingVertical: 2,
    borderRadius: 10,
  },
  backBtn: { alignItems: 'center', paddingVertical: 14, marginTop: 12 },
  backBtnLabel: { color: COLORS.textMuted, fontSize: 14, fontWeight: '500' },
});
