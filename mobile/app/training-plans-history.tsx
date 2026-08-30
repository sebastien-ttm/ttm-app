import Ionicons from '@expo/vector-icons/Ionicons';
import { Stack, useRouter } from 'expo-router';
import { useCallback, useEffect, useState } from 'react';
import {
  ActivityIndicator,
  FlatList,
  Pressable,
  RefreshControl,
  StyleSheet,
  Text,
  View,
} from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { ApiError } from '@/api/client';
import { trainingPlans as plansApi } from '@/api/resources';
import type { TrainingPlan } from '@/api/types';
import { useAuth } from '@/auth/AuthContext';
import { EmptyState, ErrorState } from '@/components/Loading';
import { COLORS, RADIUS, SHADOWS, SPACING } from '@/config';
import { canSeeTraining } from '@/utils/profile';
import { formatDate } from '@/utils/html';

const PAGE_SIZE = 20;

export default function TrainingPlansHistoryScreen() {
  const router = useRouter();
  const { user } = useAuth();
  const [plans, setPlans] = useState<TrainingPlan[]>([]);
  const [page, setPage] = useState(1);
  const [total, setTotal] = useState<number | null>(null);
  const [loading, setLoading] = useState(true);
  const [loadingMore, setLoadingMore] = useState(false);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const canSee = canSeeTraining(user);

  const loadPage = useCallback(
    async (nextPage: number, mode: 'reset' | 'append') => {
      try {
        if (mode === 'reset') setError(null);
        const resp = await plansApi.list(nextPage);
        setTotal(resp.total);
        setPlans((prev) => (mode === 'reset' ? resp.data : [...prev, ...resp.data]));
        setPage(nextPage);
      } catch (err) {
        setError(err instanceof ApiError ? err.message : 'Erreur de chargement');
      }
    },
    [],
  );

  useEffect(() => {
    if (!canSee) return;
    let cancelled = false;
    setLoading(true);
    (async () => {
      await loadPage(1, 'reset');
      if (!cancelled) setLoading(false);
    })();
    return () => {
      cancelled = true;
    };
  }, [canSee, loadPage]);

  const onRefresh = useCallback(async () => {
    setRefreshing(true);
    await loadPage(1, 'reset');
    setRefreshing(false);
  }, [loadPage]);

  const onEndReached = useCallback(async () => {
    if (loadingMore || loading) return;
    if (total !== null && plans.length >= total) return;
    setLoadingMore(true);
    await loadPage(page + 1, 'append');
    setLoadingMore(false);
  }, [loadingMore, loading, total, plans.length, page, loadPage]);

  if (!canSee) {
    return (
      <SafeAreaView style={styles.root}>
        <Stack.Screen options={{ title: 'Plans d\'entraînement' }} />
        <EmptyState
          icon="🔒"
          title="Accès réservé"
          message="Cette page est réservée aux membres qui suivent des entraînements."
        />
      </SafeAreaView>
    );
  }

  if (loading) {
    return (
      <SafeAreaView style={styles.root}>
        <Stack.Screen options={{ title: 'Historique des plans' }} />
        <View style={styles.center}>
          <ActivityIndicator size="large" color={COLORS.primary} />
        </View>
      </SafeAreaView>
    );
  }

  if (error && plans.length === 0) {
    return (
      <SafeAreaView style={styles.root}>
        <Stack.Screen options={{ title: 'Historique des plans' }} />
        <ErrorState message={error} onRetry={() => loadPage(1, 'reset')} />
      </SafeAreaView>
    );
  }

  return (
    <SafeAreaView style={styles.root} edges={['bottom']}>
      <Stack.Screen options={{ title: 'Historique des plans' }} />
      <FlatList
        contentContainerStyle={styles.content}
        data={plans}
        keyExtractor={(p) => String(p.id)}
        renderItem={({ item }) => (
          <PlanRow
            plan={item}
            onOpen={() => router.push({ pathname: '/training-plan/[id]', params: { id: String(item.id), title: item.displayTitle } } as never)}
          />
        )}
        refreshControl={
          <RefreshControl refreshing={refreshing} onRefresh={onRefresh} tintColor={COLORS.primary} />
        }
        onEndReached={onEndReached}
        onEndReachedThreshold={0.4}
        ListEmptyComponent={
          <EmptyState
            icon="📄"
            title="Aucun plan publié"
            message="Aucun plan d'entraînement n'a encore été publié pour votre profil."
          />
        }
        ListFooterComponent={
          loadingMore ? (
            <View style={styles.footerLoader}>
              <ActivityIndicator color={COLORS.primary} />
            </View>
          ) : total !== null && plans.length >= total && plans.length > 0 ? (
            <Text style={styles.footerEnd}>— Fin de l'historique —</Text>
          ) : null
        }
      />
    </SafeAreaView>
  );
}

function PlanRow({ plan, onOpen }: { plan: TrainingPlan; onOpen: () => void }) {
  return (
    <Pressable onPress={onOpen} style={({ pressed }) => [styles.row, pressed && { opacity: 0.7 }]}>
      <View style={styles.iconWrap}>
        <Ionicons name="document-text" size={22} color={COLORS.primary} />
      </View>
      <View style={{ flex: 1 }}>
        <Text style={styles.title} numberOfLines={2}>{plan.displayTitle}</Text>
        {plan.weekRangeLabel && (
          <Text style={styles.week} numberOfLines={1}>{plan.weekRangeLabel}</Text>
        )}
        <Text style={styles.meta}>
          Publié le {formatDate(plan.publishedAt ?? plan.postedAt)}
          {plan.postedBy ? ` · par ${plan.postedBy.fullName}` : ''}
        </Text>
      </View>
      <Ionicons name="chevron-forward" size={20} color={COLORS.textMuted} />
    </Pressable>
  );
}

const styles = StyleSheet.create({
  root: { flex: 1, backgroundColor: COLORS.background },
  center: { flex: 1, justifyContent: 'center', alignItems: 'center' },
  content: { padding: SPACING.lg, gap: SPACING.sm, paddingBottom: SPACING.xxl },
  row: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: SPACING.md,
    padding: SPACING.md,
    backgroundColor: COLORS.surface,
    borderRadius: RADIUS.md,
    borderWidth: 1,
    borderColor: COLORS.border,
    ...SHADOWS.sm,
  },
  iconWrap: {
    width: 40, height: 40, borderRadius: RADIUS.md,
    backgroundColor: COLORS.primarySoft,
    alignItems: 'center', justifyContent: 'center',
  },
  title: { fontSize: 15, fontWeight: '700', color: COLORS.text },
  week: { fontSize: 13, color: COLORS.secondaryDark, marginTop: 2 },
  meta: { fontSize: 12, color: COLORS.textMuted, marginTop: 3 },
  footerLoader: { padding: SPACING.lg, alignItems: 'center' },
  footerEnd: {
    textAlign: 'center',
    color: COLORS.textSubtle,
    fontSize: 12,
    paddingVertical: SPACING.lg,
    fontStyle: 'italic',
  },
});
