import { Stack, useLocalSearchParams, useRouter } from 'expo-router';
import * as WebBrowser from 'expo-web-browser';
import { useCallback, useEffect, useState } from 'react';
import { ActivityIndicator, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';

import { ApiError } from '@/api/client';
import { trainingPlans as plansApi } from '@/api/resources';
import type { TrainingPlan } from '@/api/types';
import { STORAGE_KEYS, storage } from '@/auth/storage';
import { ErrorState, FullScreenLoading } from '@/components/Loading';
import { COLORS, RADIUS, SHADOWS, SPACING } from '@/config';
import { formatDate } from '@/utils/html';

export default function TrainingPlanDetailScreen() {
  const params = useLocalSearchParams<{ id: string }>();
  const id = Number(params.id ?? 0);
  const router = useRouter();

  const [plan, setPlan] = useState<TrainingPlan | null>(null);
  const [loading, setLoading] = useState(true);
  const [opening, setOpening] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    if (!id) return;
    try {
      setError(null);
      const data = await plansApi.get(id);
      setPlan(data);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Plan introuvable');
    } finally {
      setLoading(false);
    }
  }, [id]);

  useEffect(() => {
    void load();
  }, [load]);

  async function openPdf() {
    if (!plan) return;
    setOpening(true);
    try {
      // Endpoint authentifié → on ajoute le bearer en query (cf. Lexik).
      const token = await storage.getItem(STORAGE_KEYS.accessToken);
      const sep = plan.fileUrl.includes('?') ? '&' : '?';
      const url = token ? `${plan.fileUrl}${sep}bearer=${encodeURIComponent(token)}` : plan.fileUrl;
      await WebBrowser.openBrowserAsync(url);
    } finally {
      setOpening(false);
    }
  }

  return (
    <View style={styles.container}>
      <Stack.Screen
        options={{
          title: plan?.displayTitle ?? plan?.title ?? 'Plan d\'entraînement',
          headerStyle: { backgroundColor: COLORS.primary },
          headerTintColor: '#fff',
          headerTitleStyle: { fontWeight: '700', color: '#fff' },
        }}
      />

      {loading ? (
        <FullScreenLoading />
      ) : error ? (
        <ErrorState message={error} onRetry={load} />
      ) : plan ? (
        <ScrollView contentContainerStyle={styles.content}>
          <View style={styles.card}>
            <View style={styles.iconRow}>
              <View style={styles.icon}>
                <Text style={styles.iconText}>📄</Text>
              </View>
              <View style={{ flex: 1 }}>
                <Text style={styles.title}>{plan.displayTitle}</Text>
                {plan.weekRangeLabel && (
                  <Text style={styles.week}>{plan.weekRangeLabel}</Text>
                )}
                {plan.categoryLabel && (
                  <View style={styles.badge}>
                    <Text style={styles.badgeLabel}>{plan.categoryLabel}</Text>
                  </View>
                )}
              </View>
            </View>

            {plan.description ? (
              <>
                <Text style={styles.sectionLabel}>Description</Text>
                <Text style={styles.description}>{plan.description}</Text>
              </>
            ) : (
              <Text style={styles.noDescription}>Pas de description fournie.</Text>
            )}

            <View style={styles.meta}>
              <Text style={styles.metaLabel}>Posté par</Text>
              <Text style={styles.metaValue}>{plan.postedBy.fullName}</Text>
            </View>
            <View style={styles.meta}>
              <Text style={styles.metaLabel}>Posté le</Text>
              <Text style={styles.metaValue}>{formatDate(plan.postedAt)}</Text>
            </View>
          </View>

          <Pressable
            onPress={openPdf}
            disabled={opening}
            style={({ pressed }) => [
              styles.openBtn,
              opening && styles.openBtnDisabled,
              pressed && styles.openBtnPressed,
            ]}
          >
            {opening ? (
              <ActivityIndicator color="#fff" />
            ) : (
              <Text style={styles.openLabel}>📄 Ouvrir le PDF</Text>
            )}
          </Pressable>

          <Pressable onPress={() => router.back()} style={styles.backBtn}>
            <Text style={styles.backLabel}>← Retour</Text>
          </Pressable>
        </ScrollView>
      ) : null}
    </View>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: COLORS.background },
  content: { padding: SPACING.lg, paddingBottom: SPACING.xxl },
  card: {
    backgroundColor: COLORS.surface,
    borderRadius: RADIUS.lg,
    padding: SPACING.lg,
    ...SHADOWS.sm,
    marginBottom: SPACING.lg,
  },
  iconRow: { flexDirection: 'row', gap: SPACING.md, marginBottom: SPACING.lg },
  icon: {
    width: 56,
    height: 56,
    borderRadius: RADIUS.md,
    backgroundColor: COLORS.secondarySoft,
    alignItems: 'center',
    justifyContent: 'center',
  },
  iconText: { fontSize: 28 },
  title: { fontSize: 18, fontWeight: '700', color: COLORS.text },
  week: { fontSize: 13, color: COLORS.secondaryDark, fontWeight: '600', marginTop: 4 },
  badge: {
    alignSelf: 'flex-start',
    backgroundColor: COLORS.secondarySoft,
    paddingHorizontal: 10,
    paddingVertical: 3,
    borderRadius: RADIUS.full,
    marginTop: 6,
  },
  badgeLabel: { fontSize: 12, fontWeight: '600', color: COLORS.secondaryDark },
  sectionLabel: {
    fontSize: 12,
    fontWeight: '700',
    color: COLORS.textMuted,
    textTransform: 'uppercase',
    letterSpacing: 0.5,
    marginBottom: 6,
  },
  description: {
    fontSize: 15,
    color: COLORS.text,
    lineHeight: 22,
    marginBottom: SPACING.lg,
  },
  noDescription: {
    fontSize: 14,
    color: COLORS.textMuted,
    fontStyle: 'italic',
    marginBottom: SPACING.lg,
  },
  meta: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    paddingVertical: 8,
    borderTopWidth: StyleSheet.hairlineWidth,
    borderTopColor: COLORS.border,
  },
  metaLabel: { fontSize: 13, color: COLORS.textMuted },
  metaValue: { fontSize: 13, fontWeight: '600', color: COLORS.text },
  openBtn: {
    backgroundColor: COLORS.primary,
    borderRadius: RADIUS.md,
    paddingVertical: 14,
    alignItems: 'center',
    ...SHADOWS.sm,
  },
  openBtnDisabled: { opacity: 0.6 },
  openBtnPressed: { backgroundColor: COLORS.primaryDark },
  openLabel: { color: '#fff', fontWeight: '700', fontSize: 15 },
  backBtn: { paddingVertical: 14, alignItems: 'center', marginTop: SPACING.sm },
  backLabel: { color: COLORS.textMuted, fontSize: 14, fontWeight: '600' },
});
