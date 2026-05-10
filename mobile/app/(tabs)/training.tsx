import * as WebBrowser from 'expo-web-browser';
import { useCallback, useEffect, useState } from 'react';
import { FlatList, Pressable, RefreshControl, StyleSheet, Text, View } from 'react-native';

import { ApiError } from '@/api/client';
import { trainingPlans as plansApi } from '@/api/resources';
import type { TrainingPlan } from '@/api/types';
import { STORAGE_KEYS, storage } from '@/auth/storage';
import { EmptyState, ErrorState, FullScreenLoading } from '@/components/Loading';
import { COLORS } from '@/config';
import { formatDate } from '@/utils/html';

export default function TrainingScreen() {
  const [items, setItems] = useState<TrainingPlan[]>([]);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    try {
      setError(null);
      const resp = await plansApi.list(1);
      setItems(resp.data);
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

  async function openPdf(plan: TrainingPlan) {
    // The PDF endpoint requires auth → append JWT as ?bearer= query param,
    // which Lexik recognizes server-side.
    const token = await storage.getItem(STORAGE_KEYS.accessToken);
    const sep = plan.fileUrl.includes('?') ? '&' : '?';
    const url = token ? `${plan.fileUrl}${sep}bearer=${encodeURIComponent(token)}` : plan.fileUrl;
    await WebBrowser.openBrowserAsync(url);
  }

  if (loading) return <FullScreenLoading />;

  return (
    <FlatList
      data={items}
      keyExtractor={(item) => String(item.id)}
      renderItem={({ item }) => <PlanRow plan={item} onOpen={() => openPdf(item)} />}
      contentContainerStyle={styles.content}
      refreshControl={<RefreshControl refreshing={refreshing} onRefresh={onRefresh} tintColor={COLORS.primary} />}
      ListEmptyComponent={
        error ? (
          <ErrorState message={error} onRetry={load} />
        ) : (
          <EmptyState
            icon="🏃"
            title="Pas encore de plan"
            message="Les plans d'entraînement s'afficheront ici dès qu'un entraîneur en publiera un."
          />
        )
      }
      style={{ backgroundColor: COLORS.background }}
    />
  );
}

function PlanRow({ plan, onOpen }: { plan: TrainingPlan; onOpen: () => void }) {
  return (
    <Pressable
      style={({ pressed }) => [styles.row, pressed && styles.pressed]}
      onPress={onOpen}
    >
      <View style={styles.icon}>
        <Text style={styles.iconText}>📄</Text>
      </View>
      <View style={styles.body}>
        <Text style={styles.title}>{plan.displayTitle}</Text>
        {plan.weekRangeLabel && <Text style={styles.week}>{plan.weekRangeLabel}</Text>}
        {plan.description && (
          <Text style={styles.description} numberOfLines={2}>
            {plan.description}
          </Text>
        )}
        <Text style={styles.meta}>
          Posté par {plan.postedBy.fullName} · {formatDate(plan.postedAt)}
        </Text>
      </View>
    </Pressable>
  );
}

const styles = StyleSheet.create({
  content: { padding: 12 },
  row: {
    flexDirection: 'row',
    backgroundColor: COLORS.surface,
    borderRadius: 12,
    padding: 14,
    marginBottom: 10,
    elevation: 1,
    shadowColor: '#000',
    shadowOpacity: 0.04,
    shadowRadius: 3,
    shadowOffset: { width: 0, height: 1 },
  },
  pressed: { opacity: 0.85 },
  icon: {
    width: 48,
    height: 48,
    borderRadius: 12,
    backgroundColor: '#FFE6E6',
    justifyContent: 'center',
    alignItems: 'center',
    marginRight: 12,
  },
  iconText: { fontSize: 24 },
  body: { flex: 1 },
  title: { fontSize: 16, fontWeight: '700', color: COLORS.text },
  week: { fontSize: 13, color: COLORS.primary, marginTop: 2, fontWeight: '600' },
  description: { fontSize: 13, color: COLORS.text, marginTop: 4, lineHeight: 18 },
  meta: { fontSize: 12, color: COLORS.textMuted, marginTop: 6 },
});
