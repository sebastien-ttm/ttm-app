import Ionicons from '@expo/vector-icons/Ionicons';
import { useRouter } from 'expo-router';
import { useCallback, useEffect, useState } from 'react';
import { FlatList, Pressable, RefreshControl, StyleSheet, Text, View } from 'react-native';

import { ApiError } from '@/api/client';
import { pages as pagesApi } from '@/api/resources';
import type { StaticPageNode } from '@/api/types';
import { useAuth } from '@/auth/AuthContext';
import { EmptyState, ErrorState, FullScreenLoading } from '@/components/Loading';
import { COLORS, RADIUS, SPACING } from '@/config';

/**
 * Onglet « Informations » : trombinoscopes (Comité / Staff) + arbre des
 * pages statiques du club. Le QR piscines a été déplacé vers l'onglet
 * Entraînements (à côté du contexte d'usage).
 */
export default function PracticalScreen() {
  const router = useRouter();
  const { user } = useAuth();
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
    setLoading(true);
    setTree([]);
    (async () => {
      await load();
      if (!cancelled) setLoading(false);
    })();
    return () => { cancelled = true; };
    // user?.id : l'arbre des pages statiques est filtré par audience côté API.
  }, [load, user?.id]);

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
      ListHeaderComponent={
        <View>
          <Text style={styles.sectionTitle}>👥 Comité Directeur</Text>
          <Pressable
            onPress={() => router.push('/committee' as never)}
            style={({ pressed }) => [styles.committeeCard, pressed && { opacity: 0.7 }]}
          >
            <View style={styles.committeeIcon}>
              <Ionicons name="people" size={24} color="#fff" />
            </View>
            <View style={{ flex: 1 }}>
              <Text style={styles.committeeTitle}>Bureau et Membres</Text>
            </View>
            <Ionicons name="chevron-forward" size={20} color={COLORS.textMuted} />
          </Pressable>

          <Text style={styles.sectionTitle}>🏅 Encadrement sportif</Text>
          <Pressable
            onPress={() => router.push('/staff' as never)}
            style={({ pressed }) => [styles.committeeCard, pressed && { opacity: 0.7 }]}
          >
            <View style={styles.committeeIcon}>
              <Ionicons name="fitness" size={24} color="#fff" />
            </View>
            <View style={{ flex: 1 }}>
              <Text style={styles.committeeTitle}>Entraîneurs & Encadrants</Text>
            </View>
            <Ionicons name="chevron-forward" size={20} color={COLORS.textMuted} />
          </Pressable>
          {tree.length > 0 && <Text style={styles.sectionTitle}>📚 Informations</Text>}
        </View>
      }
      contentContainerStyle={styles.content}
      refreshControl={<RefreshControl refreshing={refreshing} onRefresh={onRefresh} tintColor={COLORS.primary} />}
      ListEmptyComponent={
        error ? (
          <ErrorState message={error} onRetry={load} />
        ) : null
      }
      style={{ backgroundColor: COLORS.background }}
    />
  );
}

function PageNodeRow({ node, depth }: { node: StaticPageNode; depth: number }) {
  const router = useRouter();
  const [expanded, setExpanded] = useState(depth === 0);

  const onTap = () => { router.push(`/page/${node.slug}` as never); };
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
  committeeCard: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 12,
    backgroundColor: COLORS.surface,
    borderRadius: RADIUS.md,
    padding: SPACING.md,
    marginHorizontal: SPACING.md,
    marginTop: SPACING.md,
    marginBottom: 0,
    borderLeftWidth: 4,
    borderLeftColor: COLORS.brandNavy,
  },
  committeeIcon: {
    width: 44,
    height: 44,
    borderRadius: 8,
    backgroundColor: COLORS.brandNavy,
    alignItems: 'center',
    justifyContent: 'center',
  },
  committeeTitle: { fontSize: 15, fontWeight: '700', color: COLORS.text },
  committeeSub: { fontSize: 12, color: COLORS.textMuted, marginTop: 2 },
  sectionTitle: {
    fontSize: 14,
    fontWeight: '700',
    color: COLORS.textMuted,
    paddingHorizontal: 16,
    paddingTop: 16,
    paddingBottom: 8,
    textTransform: 'uppercase',
    letterSpacing: 0.5,
  },
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
