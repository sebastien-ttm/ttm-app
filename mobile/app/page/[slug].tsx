import Ionicons from '@expo/vector-icons/Ionicons';
import { Stack, useLocalSearchParams, useRouter } from 'expo-router';
import { useCallback, useEffect, useState } from 'react';
import { Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';

import { ApiError } from '@/api/client';
import { pages as pagesApi } from '@/api/resources';
import type { StaticPage, StaticPageNode } from '@/api/types';
import { ErrorState, FullScreenLoading } from '@/components/Loading';
import { COLORS } from '@/config';
import { htmlToText } from '@/utils/html';

export default function PageScreen() {
  const params = useLocalSearchParams<{ slug: string }>();
  const slug = String(params.slug ?? '');

  const [page, setPage] = useState<StaticPage | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    if (!slug) return;
    try {
      setError(null);
      const data = await pagesApi.get(slug);
      setPage(data);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Page introuvable');
    } finally {
      setLoading(false);
    }
  }, [slug]);

  useEffect(() => {
    void load();
  }, [load]);

  const text = page ? htmlToText(page.content).trim() : '';
  const hasContent = text.length > 0;
  const hasChildren = (page?.children?.length ?? 0) > 0;

  return (
    <View style={styles.container}>
      {/* Always render so the header title is immediately controlled and never falls back to the route filename. */}
      <Stack.Screen options={{ title: page?.title ?? '' }} />
      {loading ? (
        <FullScreenLoading />
      ) : error ? (
        <ErrorState message={error} onRetry={load} />
      ) : page ? (
        <ScrollView contentContainerStyle={styles.content}>
          <Text style={styles.title}>{page.title}</Text>
          {hasContent && <Text style={styles.body}>{text}</Text>}

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
});
