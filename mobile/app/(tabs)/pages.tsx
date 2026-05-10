import Ionicons from '@expo/vector-icons/Ionicons';
import { useRouter } from 'expo-router';
import { useCallback, useEffect, useState } from 'react';
import { FlatList, Pressable, RefreshControl, StyleSheet, Text, View } from 'react-native';

import { ApiError } from '@/api/client';
import { pages as pagesApi } from '@/api/resources';
import type { StaticPageNode } from '@/api/types';
import { EmptyState, ErrorState, FullScreenLoading } from '@/components/Loading';
import { COLORS } from '@/config';

export default function PagesScreen() {
  const [tree, setTree] = useState<StaticPageNode[]>([]);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    try {
      setError(null);
      const resp = await pagesApi.tree();
      setTree(resp.data);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Erreur de chargement');
    }
  }, []);

  useEffect(() => {
    let cancelled = false;
    (async () => {
      await load();
      if (!cancelled) setLoading(false);
    })();
    return () => {
      cancelled = true;
    };
  }, [load]);

  const onRefresh = useCallback(async () => {
    setRefreshing(true);
    await load();
    setRefreshing(false);
  }, [load]);

  if (loading) return <FullScreenLoading />;

  return (
    <FlatList
      data={tree}
      keyExtractor={(item) => item.slug}
      renderItem={({ item }) => <PageNodeRow node={item} depth={0} />}
      contentContainerStyle={styles.content}
      refreshControl={<RefreshControl refreshing={refreshing} onRefresh={onRefresh} tintColor={COLORS.primary} />}
      ListEmptyComponent={
        error ? (
          <ErrorState message={error} onRetry={load} />
        ) : (
          <EmptyState
            icon="📚"
            title="Aucune page"
            message="Les pages utiles du club s'afficheront ici."
          />
        )
      }
      style={{ backgroundColor: COLORS.background }}
    />
  );
}

function PageNodeRow({ node, depth }: { node: StaticPageNode; depth: number }) {
  const router = useRouter();
  const [expanded, setExpanded] = useState(depth === 0); // first level open by default

  const onTap = () => {
    router.push(`/page/${node.slug}` as never);
  };

  const onToggle = (e: { stopPropagation: () => void }) => {
    e.stopPropagation();
    setExpanded((v) => !v);
  };

  return (
    <View>
      <Pressable
        style={({ pressed }) => [
          styles.row,
          { paddingLeft: 16 + depth * 18 },
          pressed && styles.rowPressed,
        ]}
        onPress={onTap}
      >
        {node.hasChildren ? (
          <Pressable onPress={onToggle} hitSlop={10} style={styles.chevronWrap}>
            <Ionicons
              name={expanded ? 'chevron-down' : 'chevron-forward'}
              size={16}
              color={COLORS.textMuted}
            />
          </Pressable>
        ) : (
          <View style={styles.chevronWrap}>
            <View style={styles.dot} />
          </View>
        )}
        <Text style={[styles.label, depth === 0 && styles.labelRoot]} numberOfLines={2}>
          {node.title}
        </Text>
        <Ionicons name="chevron-forward" size={18} color={COLORS.textMuted} />
      </Pressable>
      {expanded &&
        node.children.map((child) => <PageNodeRow key={child.slug} node={child} depth={depth + 1} />)}
    </View>
  );
}

const styles = StyleSheet.create({
  content: { paddingVertical: 8 },
  row: {
    flexDirection: 'row',
    alignItems: 'center',
    paddingVertical: 14,
    paddingRight: 14,
    backgroundColor: COLORS.surface,
    borderBottomWidth: StyleSheet.hairlineWidth,
    borderBottomColor: COLORS.border,
    gap: 8,
  },
  rowPressed: { backgroundColor: COLORS.background },
  chevronWrap: { width: 24, alignItems: 'center', justifyContent: 'center' },
  dot: { width: 4, height: 4, borderRadius: 2, backgroundColor: COLORS.border },
  label: { flex: 1, fontSize: 15, color: COLORS.text },
  labelRoot: { fontWeight: '600' },
});
