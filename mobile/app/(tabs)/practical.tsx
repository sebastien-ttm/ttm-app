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
import { canSeePoolBadge } from '@/utils/profile';

/**
 * Onglet « Pratique » : raccourci Accès Piscine (QR code) en haut + arbre
 * des pages statiques du club. Le QR n'apparaît que pour les comptes
 * licenciés (canSeePoolBadge — Phase D).
 */
export default function PracticalScreen() {
  const router = useRouter();
  const { user } = useAuth();
  const [tree, setTree] = useState<StaticPageNode[]>([]);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const showPool = canSeePoolBadge(user);

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
    return () => { cancelled = true; };
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
      ListHeaderComponent={
        showPool ? (
          <Pressable
            onPress={() => router.push('/pool-badge' as never)}
            style={({ pressed }) => [styles.poolCard, pressed && { opacity: 0.7 }]}
          >
            <View style={styles.poolIcon}>
              <Ionicons name="qr-code" size={26} color="#fff" />
            </View>
            <View style={{ flex: 1 }}>
              <Text style={styles.poolTitle}>Accès piscines</Text>
              <Text style={styles.poolSub}>Afficher le QR code à présenter à l'entrée</Text>
            </View>
            <Ionicons name="chevron-forward" size={20} color={COLORS.textMuted} />
          </Pressable>
        ) : null
      }
      contentContainerStyle={styles.content}
      refreshControl={<RefreshControl refreshing={refreshing} onRefresh={onRefresh} tintColor={COLORS.primary} />}
      ListEmptyComponent={
        error ? (
          <ErrorState message={error} onRetry={load} />
        ) : !showPool ? (
          <EmptyState
            icon="📚"
            title="Aucune page"
            message="Les pages utiles du club s'afficheront ici."
          />
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
  poolCard: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 12,
    backgroundColor: COLORS.surface,
    borderRadius: RADIUS.md,
    padding: SPACING.md,
    margin: SPACING.md,
    borderLeftWidth: 4,
    borderLeftColor: COLORS.primary,
  },
  poolIcon: {
    width: 44,
    height: 44,
    borderRadius: 8,
    backgroundColor: COLORS.brandNavy,
    alignItems: 'center',
    justifyContent: 'center',
  },
  poolTitle: { fontSize: 15, fontWeight: '700', color: COLORS.text },
  poolSub: { fontSize: 12, color: COLORS.textMuted, marginTop: 2 },
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
